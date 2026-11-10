<?php
// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);
namespace Panel\Services;

use Panel\Core\{Database,Validator,AppException};

/** Each step performs at most one remote request. Reads never write Cloudflare. */
final class SaasInventory
{
    private const PAGE_SIZE=100;
    public function __construct(private Database $db,private Cloud $cloud) {}
    public function demo():bool{return $this->db->value("SELECT value FROM settings WHERE name='preview_mode'")==='1';}
    public function begin(array $input,string $actor):array
    {
        $accounts=$input['account_ids']??[];
        if(!is_array($accounts)||!array_is_list($accounts)||count($accounts)>100){throw new AppException('请选择有效的账号列表');}
        foreach($accounts as $id){if(!is_int($id)||$id<1||!$this->db->value('SELECT id FROM accounts WHERE id=? AND active=1 AND deleted_at IS NULL',[$id])){throw new AppException('读取账号不存在或未启用');}}
        $syncErrors=$input['sync_errors']??[];if(!is_array($syncErrors)||!array_is_list($syncErrors)||count($syncErrors)>100||array_filter($syncErrors,fn($error)=>!is_string($error)||mb_strlen($error)>512)){throw new AppException('账号同步结果格式不正确');}
        $zones=$this->db->all("SELECT z.*,a.name AS account_name FROM zones z JOIN accounts a ON a.id=z.account_id WHERE a.active=1 AND a.deleted_at IS NULL AND z.status='active' ORDER BY a.id,z.name");
        if(!$zones){throw new AppException('请先添加账号并同步主域名');}if(count($zones)>1000){throw new AppException('一次读取最多 1000 个已授权 Zone');}
        $id=$this->db->transaction(function()use($zones,$accounts,$actor,$syncErrors):int{
            $id=$this->db->insert('INSERT INTO discovery_runs(demo,cursor_json) VALUES(?,?)',[$this->demo()?1:0,Validator::json(['zone'=>0,'phase'=>'dns','page'=>1,'retries'=>0,'link'=>0,'sync_errors'=>$syncErrors])]);
            foreach($zones as $position=>$zone){$this->db->run('INSERT INTO discovery_zones(run_id,position,zone_ref,account_id,account_name,zone_id,zone_name,read_saas,dns_json,hostnames_json,errors_json) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[$id,$position,$zone['id'],$zone['account_id'],$zone['account_name'],$zone['cf_id'],$zone['name'],!$accounts||in_array((int)$zone['account_id'],$accounts,true)?1:0,'[]','[]','{}']);}
            (new Audit($this->db))->write($actor,'inventory.started','读取已有 SaaS / DNS：'.count($zones).' 个授权 Zone，批次 #'.$id);return $id;
        });
        return $this->status($id);
    }
    public function status(?int $id=null):array
    {
        $run=$id?$this->db->one('SELECT * FROM discovery_runs WHERE id=?',[$id]):$this->db->one('SELECT * FROM discovery_runs ORDER BY id DESC LIMIT 1');
        if(!$run){if($id){throw new AppException('读取批次不存在',404);}return ['run'=>null];}
        $zones=$this->db->all('SELECT position,zone_ref,account_name,zone_name,read_saas,dns_status,fallback_status,hostnames_status,errors_json,JSON_LENGTH(dns_json) AS dns_count,JSON_LENGTH(hostnames_json) AS hostname_count FROM discovery_zones WHERE run_id=? ORDER BY position',[$run['id']]);
        foreach($zones as &$zone){$zone['errors']=json_decode($zone['errors_json'],true);unset($zone['errors_json']);}unset($zone);
        $cursor=json_decode($run['cursor_json'],true);
        return ['run'=>['id'=>(int)$run['id'],'state'=>$run['state'],'source'=>$run['demo']?'demo':'cloudflare','created_at'=>$run['created_at'],'updated_at'=>$run['updated_at'],'steps'=>(int)$run['steps'],'cursor'=>$cursor,'retry_after'=>max(0,($cursor['retry_at']??0)-time()),'total'=>(int)$this->db->value('SELECT COUNT(*) FROM discovery_items WHERE run_id=?',[$run['id']])],'zones'=>$zones];
    }
    public function assertMode(array $run):void
    {
        if((bool)$run['demo']!==$this->demo()){throw new AppException('读取批次与当前演示 / 正式模式不同，请重新读取',409);}
    }
    public function step(int $id,string $actor):array
    {
        $lock='cf_panel_inventory_'.$id;if(!(int)$this->db->value('SELECT GET_LOCK(?,0)',[$lock])){throw new AppException('该批次正在读取，请稍后继续',409);}
        try{
            $run=$this->db->one('SELECT * FROM discovery_runs WHERE id=?',[$id]);if(!$run){throw new AppException('读取批次不存在',404);}$this->assertMode($run);
            if($run['state']==='completed'){return $this->status($id);}$cursor=json_decode($run['cursor_json'],true);
            if(($cursor['retry_at']??0)>time()){return $this->status($id);}
            $zone=$this->db->one('SELECT * FROM discovery_zones WHERE run_id=? AND position=?',[$id,$cursor['zone']]);
            if(!$zone){
                $links=new InventoryLinks($this->db,$id);$items=$links->subjects();$slice=array_slice($items,$cursor['link'],100);
                $this->db->transaction(function()use($slice,$links,$id):void{foreach($slice as $subject){$item=$links->analyse($subject);$this->db->run('INSERT INTO discovery_items(run_id,item_key,hostname,account_id,zone_ref,payload_json) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE payload_json=VALUES(payload_json)',[$id,$item['key'],$item['hostname'],$item['account_id'],$item['zone_ref'],Validator::json($item)]);}});
                $cursor['link']+=count($slice);$state=$cursor['link']>=count($items)?'completed':'linking';
                $this->db->run('UPDATE discovery_runs SET state=?,cursor_json=?,steps=steps+1 WHERE id=?',[$state,Validator::json($cursor),$id]);
                if($state==='completed'){(new Audit($this->db))->write($actor,'inventory.completed','已有 SaaS 关联读取完成，批次 #'.$id.'，'.count($items).' 条主机名；错误见各 Zone 状态');}
                return $this->status($id);
            }
            $phase=$cursor['phase'];$page=(int)$cursor['page'];$field=$phase==='hosts'?'hostnames':$phase;
            try{
                if($phase!=='dns'&&!$zone['read_saas']){$response=['result'=>[],'result_info'=>['total_pages'=>1]];}
                else{$response=$this->readPage($zone,$phase,$page);}
                if($phase==='fallback'){if(!is_array($response['result']??null)){throw new AppException('Cloudflare 默认回源响应格式不正确',503);}$this->db->run("UPDATE discovery_zones SET fallback_json=?,fallback_status='completed' WHERE run_id=? AND position=?",[Validator::json($response['result']),$id,$cursor['zone']]);$done=true;}
                else{
                    if(!is_array($response['result']??null)||!array_is_list($response['result'])||array_filter($response['result'],fn($row)=>!is_array($row))){throw new AppException('Cloudflare 返回的列表格式不正确',503);}
                    $rows=$phase==='dns'?ObservedDns::records($response['result']):array_map(self::hostname(...),$response['result']);
                    $existing=json_decode($zone[$field.'_json'],true);$merged=array_merge($existing,$rows);
                    $total=(int)$this->db->value('SELECT COALESCE(SUM(JSON_LENGTH('.$field.'_json)),0) FROM discovery_zones WHERE run_id=?',[$id])+count($rows);
                    if(count($merged)>50000||$total>($phase==='dns'?50000:10000)||strlen(Validator::json($merged))>8000000){throw new AppException('读取数据超过本批次上限（DNS 50000 / SaaS 10000 条或单 Zone 8 MB），结果不完整，请缩小授权范围后重新读取',422,'inventory_limit');}
                    $pages=$response['result_info']['total_pages']??null;$done=$pages!==null?$page>=max(1,(int)$pages):count($rows)<self::PAGE_SIZE;
                    if(!$done&&$page>=1000){throw new AppException('分页超过上限，读取未完成');}
                    $this->db->run('UPDATE discovery_zones SET '.$field.'_json=?,'.$field.'_status=? WHERE run_id=? AND position=?',[Validator::json($merged),$done?'completed':'reading',$id,$cursor['zone']]);
                    if($phase==='dns'&&$done){(new ZoneDns($this->db))->store((int)$zone['zone_ref'],$merged);}
                }
                $errors=json_decode($zone['errors_json'],true);unset($errors[$phase]);$this->db->run('UPDATE discovery_zones SET errors_json=? WHERE run_id=? AND position=?',[Validator::json($errors),$id,$cursor['zone']]);$cursor['retries']=0;$cursor['retry_at']=0;
                if(!$done){$cursor['page']++;}else{$cursor=$this->next($cursor);}
            }catch(AppException $e){
                if($phase==='fallback'&&$e->status===404){
                    $errors=json_decode($zone['errors_json'],true);unset($errors['fallback']);$this->db->run("UPDATE discovery_zones SET fallback_status='not_configured',fallback_json='{}',errors_json=? WHERE run_id=? AND position=?",[Validator::json($errors),$id,$zone['position']]);$cursor=$this->next($cursor);
                }else{
                $errors=json_decode($zone['errors_json'],true);$errors[$phase]=$e->getMessage();$retry=$e->status>=500&&$cursor['retries']<2;$cursor['retries']++;
                if($retry){$cursor['retry_at']=time()+max(2,(int)($e->retryAfter??0));}
                $this->db->run('UPDATE discovery_zones SET '.$field.'_status=?,errors_json=? WHERE run_id=? AND position=?',[$retry?'retry':'failed',Validator::json($errors),$id,$zone['position']]);
                if(!$retry){$cursor=$this->next($cursor);}
                }
            }
            $this->db->run('UPDATE discovery_runs SET cursor_json=?,steps=steps+1 WHERE id=?',[Validator::json($cursor),$id]);return $this->status($id);
        }finally{$this->db->value('SELECT RELEASE_LOCK(?)',[$lock]);}
    }
    private function next(array $cursor):array
    {
        $cursor['page']=1;$cursor['retries']=0;$cursor['retry_at']=0;
        if($cursor['phase']==='dns'){$cursor['phase']='fallback';}elseif($cursor['phase']==='fallback'){$cursor['phase']='hosts';}else{$cursor['phase']='dns';$cursor['zone']++;}return $cursor;
    }
    public static function hostname(array $row):array
    {
        $ssl=$row['ssl']??[];
        $normalize=static function(string $name):string{try{return Validator::hostname($name);}catch(AppException){return strtolower(rtrim($name,'.'));}};
        return ['id'=>(string)($row['id']??''),'hostname'=>$normalize((string)($row['hostname']??'')),'status'=>(string)($row['status']??'unknown'),'ssl'=>['status'=>(string)($ssl['status']??'unknown')],'custom_origin_server'=>$normalize((string)($row['custom_origin_server']??'')),'custom_origin_sni'=>(string)($row['custom_origin_sni']??'')];
    }
    public function readPage(array $zone,string $phase,int $page):array
    {
        if($this->demo()){
            $fixture=$this->demoZone((int)$zone['zone_ref']);$data=$phase==='fallback'?($fixture['fallback']??[]):($fixture[$phase==='dns'?'dns':'hosts']??[]);
            return $phase==='fallback'?['result'=>$data]:['result'=>array_slice($data,($page-1)*self::PAGE_SIZE,self::PAGE_SIZE),'result_info'=>['total_pages'=>max(1,(int)ceil(count($data)/self::PAGE_SIZE))]];
        }
        $client=$this->cloud->client((int)$zone['account_id']);$path='/zones/'.$zone['zone_id'].'/'.($phase==='dns'?'dns_records':($phase==='fallback'?'custom_hostnames/fallback_origin':'custom_hostnames'));
        return $client->request('GET',$path,null,$phase==='fallback'?[]:['page'=>$page,'per_page'=>self::PAGE_SIZE]);
    }
    public function currentRecords(array $zone,string $hostname):array
    {
        if($this->demo()){return array_values(array_filter($this->demoZone((int)$zone['zone_ref'])['dns']??[],fn($r)=>strtolower(rtrim($r['name'],'.'))===$hostname));}
        return $this->cloud->dns((int)$zone['account_id'])->records($zone['zone_id'],$hostname);
    }
    public function currentHostname(array $zone,string $id):array
    {
        if($this->demo()){foreach($this->demoZone((int)$zone['zone_ref'])['hosts']??[] as $row){if($row['id']===$id){return self::hostname($row);}}throw new AppException('示例主机名不存在',404);}
        return self::hostname($this->cloud->client((int)$zone['account_id'])->request('GET','/zones/'.$zone['zone_id'].'/custom_hostnames/'.$id)['result']);
    }
    private function demoZone(int $zoneRef):array
    {
        $fixture=$this->db->value("SELECT value FROM settings WHERE name='demo_inventory_json'");if($fixture){$data=json_decode($fixture,true,64,JSON_THROW_ON_ERROR);return $data[(string)$zoneRef]??['dns'=>[],'hosts'=>[],'fallback'=>[]];}
        $zone=$this->db->one('SELECT * FROM zones WHERE id=?',[$zoneRef]);$dns=[];$hosts=[];$fallback=[];
        foreach($this->db->all('SELECT * FROM profiles WHERE account_id=? AND zone_id=? AND deleted_at IS NULL',[$zone['account_id'],$zone['cf_id']]) as $profile){
            $fallback=['origin'=>$profile['fallback_origin'],'status'=>'active'];if($profile['cname_target']!==''){$dns[]=['id'=>'demo-standard-'.$profile['id'],'name'=>$profile['cname_target'],'type'=>'CNAME','content'=>$profile['fallback_origin'],'proxied'=>true,'ttl'=>1];}
            foreach($this->db->all('SELECT * FROM domains WHERE profile_id=? AND deleted_at IS NULL',[$profile['id']]) as $domain){$config=(new DomainConfig($this->db))->applied($domain);if(!DnsMode::preferred($config)){continue;}$hosts[]=['id'=>$domain['cf_hostname_id']?:'demo-existing-'.$domain['id'],'hostname'=>$domain['hostname'],'status'=>'active','ssl'=>['status'=>'active'],'custom_origin_server'=>$config['origin']['mode']==='custom'?$config['origin']['hostname']:''];}
        }
        foreach($this->db->all('SELECT * FROM domains WHERE dns_account_id=? AND dns_zone_id=? AND deleted_at IS NULL',[$zone['account_id'],$zone['cf_id']]) as $domain){foreach(DnsMode::desired($domain,(new DomainConfig($this->db))->applied($domain)) as $i=>$record){$dns[]=$record+['id'=>'demo-dns-'.$domain['id'].'-'.$i];}}
        foreach($this->db->all('SELECT * FROM entries WHERE zone_ref=? AND deleted_at IS NULL',[$zoneRef]) as $entry){foreach(EntryAlias::desired($entry) as $i=>$record){$dns[]=$record+['id'=>'demo-entry-'.$entry['id'].'-'.$i,'comment'=>'cf-saas-panel:entry:'.$entry['id']];}}
        foreach($this->db->all('SELECT o.* FROM origins o JOIN profiles p ON p.id=o.profile_id WHERE p.account_id=? AND o.deleted_at IS NULL',[$zone['account_id']]) as $origin){if(!Validator::zoneMatches($origin['hostname'],$zone['name'])){continue;}if(!empty($origin['external_dns_json'])){foreach(json_decode($origin['external_dns_json'],true)['records'] as $record){$dns[$record['id']]=$record;}continue;}$source=$origin['source_ip']??$origin['source_hostname']??null;if($source===null){continue;}$dns[]=['id'=>'demo-origin-'.$origin['id'],'name'=>$origin['hostname'],'type'=>filter_var($source,FILTER_VALIDATE_IP)?(str_contains($source,':')?'AAAA':'A'):'CNAME','content'=>$source,'proxied'=>true,'ttl'=>1];}
        $unique=[];foreach($dns as $record){$unique[$record['id']]=$record;}$dns=array_values($unique);
        return ['dns'=>$dns,'hosts'=>$hosts,'fallback'=>$fallback];
    }
    public function items(int $id,int $page=1,int $per=20,string $search=''):array
    {
        $this->status($id);$where='run_id=?';$params=[$id];if($search!==''){$where.=' AND hostname LIKE ?';$params[]='%'.mb_substr($search,0,253).'%';}
        $rows=$this->db->all('SELECT payload_json FROM discovery_items WHERE '.$where.' ORDER BY hostname,item_key LIMIT '.max(1,min(100,$per)).' OFFSET '.((max(1,$page)-1)*max(1,min(100,$per))),$params);
        $items=[];foreach($rows as $row){$item=json_decode($row['payload_json'],true);$existing=$this->db->one('SELECT id,status FROM domains WHERE hostname=? AND deleted_at IS NULL',[$item['hostname']]);$item['managed']=$existing;$items[]=$item;}
        return ['items'=>$items,'total'=>(int)$this->db->value('SELECT COUNT(*) FROM discovery_items WHERE '.$where,$params),'page'=>$page,'per_page'=>$per];
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);
namespace Panel\Services;
use Panel\Core\{Database,Validator,AppException};

/** Explicitly submitted DNS mutations, with durable intent and before-write reconciliation. */
final class DnsOperations
{
    public function __construct(private Database $db,private Cloud $cloud) {}
    public function plan(int $zoneId,array $reference,string $action,array $input,string $actor,bool $preview):array
    {
        $zone=(new ZoneRecords($this->db))->zone($zoneId,true);
        $id=substr($reference['id'],3);$records=(new ZoneDns($this->db))->snapshot($zoneId)['records'];$matches=array_values(array_filter($records,fn($r)=>$r['id']===$id));
        if(count($matches)!==1){throw new AppException('原记录不在该主域名的最新快照内，请先同步',404);}$before=$matches[0];
        if(!hash_equals(ObservedDns::fingerprint([$before]),$reference['expected_fingerprint']??'')){throw new AppException('DNS 快照已更新，请重新选择后预览',409,'dns_version_conflict');}
        if(in_array($before['type'],['A','AAAA','CNAME'],true)&&$this->db->value('SELECT id FROM domains WHERE hostname=? AND dns_account_id=? AND dns_zone_id=? AND deleted_at IS NULL',[$before['name'],$zone['account_id'],$zone['cf_id']])){throw new AppException('此名称已纳管，请使用列表中的纳管记录编辑或切换',409);}
        if($this->db->value("SELECT id FROM dns_operations WHERE zone_ref=? AND status IN ('queued','waiting','waiting_domain') AND (record_id=? OR hostname=? AND action='preferred')",[$zoneId,$id,$before['name']])){throw new AppException('该记录已有待执行修改，请等待任务完成后再操作',409);}
        $fresh=$this->current($zone,$before);
        if(!$fresh){throw new AppException('原记录已在 Cloudflare 删除，请重新同步',409,'dns_external_change');}
        ObservedDns::assertSame([$before],[$fresh],$before['name']);
        if(!empty($fresh['locked'])||!empty($fresh['meta']['managed_by_apps'])||!empty($fresh['meta']['managed_by_argo_tunnel'])){throw new AppException('该记录由 Cloudflare 其他服务管理，不能直接修改');}
        $options=[];$beforeSet=[$before];$after=null;$message='';
        if($action==='update'){$options['changes']=DnsRecordInput::changes($before,$reference['changes']??$input['changes']??[],$zone['name']);$after=[array_replace($before,$options['changes'])];}
        elseif($action==='delete'){$message='确认后仅删除选中的 CF 记录 ID，无关记录保留';}
        elseif($action==='set_mode'){
            if(!in_array($before['type'],['A','AAAA','CNAME'],true)){throw new AppException('只有 A / AAAA / CNAME 支持关云、开小黄云和优选');}
            $mode=DnsMode::validate($input['dns_mode']??null);
            if($mode==='preferred'){
                $action='preferred';$beforeSet=$this->currentSet($zone,$before['name']);
                if(isset($reference['expected_rrset'])&&!hash_equals(ObservedDns::fingerprint($beforeSet),$reference['expected_rrset'])){throw new AppException('同名记录已变化，请重新预览优选切换范围',409,'dns_external_change');}
                if(!$preview&&!isset($reference['expected_rrset'])){throw new AppException('请先预览优选计划，确认完整同名记录范围');}
                $plan=(new DnsPreference($this->db,$this->cloud))->plan($zone,$before,$beforeSet,$input);$options=$plan['options'];$after=$plan['after'];$message=$plan['message'];
            }else{$options['changes']=['proxied'=>$mode==='proxied'];if($mode==='proxied'){$options['changes']['ttl']=1;}$after=[array_replace($before,$options['changes'])];}
        }else{throw new AppException('不支持的原 DNS 操作');}
        $references=$this->references($zone,$before['name']);$unchanged=$after!==null&&ObservedDns::fingerprint($beforeSet)===ObservedDns::fingerprint($after);
        // Preferred mode can still need SaaS adoption even when the DNS targets match.
        if($action==='preferred'){$unchanged=false;}
        $result=['id'=>$reference['id'],'hostname'=>$before['name'],'expected_fingerprint'=>$reference['expected_fingerprint'],'expected_rrset'=>ObservedDns::fingerprint($beforeSet),'before'=>$beforeSet,'after'=>$after,'references'=>$references,'message'=>$message,'status'=>$unchanged?'unchanged':($preview?'ready':'queued')];
        if(isset($options['changes'])){$result['changes']=$options['changes'];}
        if(!$preview&&!$unchanged){
            if($action==='preferred'){$options['approved_dns']=$beforeSet;}
            $options['selected_record']=$before;
            $operation=$this->db->insert('INSERT INTO dns_operations(zone_ref,record_id,hostname,action,actor,before_json,after_json,options_json) VALUES(?,?,?,?,?,?,?,?)',[$zoneId,$id,$before['name'],$action,$actor,Validator::json($beforeSet),$after===null?null:Validator::json($after),Validator::json($options)]);
            $result['operation_id']=$operation;
            (new Audit($this->db))->write($actor,'dns.submitted','提交原 DNS '.$action.'：'.$before['name'].' / '.$id.'，任务 #'.$operation.'；后台执行前再次核对原记录');
        }
        return $result;
    }
    private function current(array $zone,array $record):?array
    {
        if((new SaasInventory($this->db,$this->cloud))->demo()){foreach($this->currentSet($zone,$record['name']) as $row){if($row['id']===$record['id']){return $row;}}return null;}
        return $this->cloud->dns((int)$zone['account_id'])->get($zone['cf_id'],$record['id']);
    }
    private function currentSet(array $zone,string $name):array
    {
        return (new SaasInventory($this->db,$this->cloud))->currentRecords(['zone_ref'=>$zone['id'],'zone_id'=>$zone['cf_id'],'account_id'=>$zone['account_id']],$name);
    }
    private function references(array $zone,string $name):array
    {
        $refs=[];
        foreach($this->db->all('SELECT hostname,applied_config FROM domains WHERE deleted_at IS NULL') as $domain){$config=json_decode($domain['applied_config']??'{}',true);$source=$config['origin']??[];$entry=$config['entry']??[];
            if(($source['hostname']??null)===$name&&(int)($source['external_dns']['account_id']??0)===(int)$zone['account_id']||($entry['hostname']??null)===$name&&(int)($entry['dns_account_id']??0)===(int)$zone['account_id']){$refs[]=$domain['hostname'];}}
        return array_values(array_unique($refs));
    }
    public function detail(int $id):array
    {
        $row=$this->db->one('SELECT * FROM dns_operations WHERE id=?',[$id]);if(!$row){throw new AppException('DNS 修改任务不存在',404);}
        foreach(['before_json','after_json','options_json'] as $field){$row[substr($field,0,-5)]=$row[$field]===null?null:json_decode($row[$field],true);unset($row[$field]);}return $row;
    }
    public function retry(int $id,string $actor):array
    {
        $row=$this->detail($id);if(!in_array($row['status'],['failed','needs_attention'],true)){throw new AppException('只有失败或待处理的 DNS 任务可以重试',409);}
        if($row['domain_id']&&$row['job_id']){(new DomainService($this->db))->action((int)$row['domain_id'],'retry',$actor);}
        $this->db->run("UPDATE dns_operations SET status=?,attempts=0,last_error=NULL,run_at=UTC_TIMESTAMP() WHERE id=?",[$row['domain_id']?'waiting_domain':'queued',$id]);
        (new Audit($this->db))->write($actor,'dns.retry','重试 DNS 修改任务 #'.$id.'，仍核对原始基线');return ['operation_id'=>$id];
    }
    public function once():bool
    {
        $op=$this->db->one("SELECT * FROM dns_operations WHERE status IN ('queued','waiting','waiting_domain') AND run_at<=UTC_TIMESTAMP() ORDER BY (status='queued') DESC,run_at,id LIMIT 1");
        if(!$op){return false;}
        $lock='cf_panel_dns_'.$op['zone_ref'];if(!(int)$this->db->value('SELECT GET_LOCK(?,0)',[$lock])){return false;}
        try{
            $zone=(new ZoneRecords($this->db))->zone((int)$op['zone_ref'],true);$options=json_decode($op['options_json'],true);$before=json_decode($op['before_json'],true);$after=$op['after_json']===null?null:json_decode($op['after_json'],true);
            if($op['action']==='preferred'){return $this->preferred($op,$zone,$options,$before);}
            $dns=$this->cloud->dns((int)$zone['account_id']);$current=$dns->get($zone['cf_id'],$op['record_id']);
            if($op['write_planned']&&($after===null&&$current===null||$after!==null&&$current!==null&&$this->matchesIntent($current,$before[0],$options['changes']))){$this->complete($op,$current);return true;}
            if(!$current){throw new AppException('执行前原记录已被删除，停止覆盖',409,'dns_external_change');}
            ObservedDns::assertSame($before,[$current],$op['hostname']);
            $this->db->run('UPDATE dns_operations SET write_planned=1 WHERE id=?',[$op['id']]);
            if($after===null){$dns->delete($zone['cf_id'],$op['record_id']);$result=$dns->get($zone['cf_id'],$op['record_id']);if($result!==null){throw new AppException('DNS 删除尚未确认，等待重新核对',503);}}
            else{
                $this->cloud->client((int)$zone['account_id'])->request('PATCH','/zones/'.$zone['cf_id'].'/dns_records/'.$op['record_id'],$options['changes']);
                $result=$dns->get($zone['cf_id'],$op['record_id']);
                if(!$result||!$this->matchesChanges($result,$options['changes'])){throw new AppException('DNS 修改尚未读回确认，稍后重新核对',503);}
            }
            $this->complete($op,$result);return true;
        }catch(\Throwable $e){
            $attempt=(int)$op['attempts']+1;$retry=$e instanceof AppException&&$e->status>=500&&$attempt<=8;$message=$e instanceof AppException?$e->getMessage():'DNS 执行异常，请查看服务器日志';
            if(!$e instanceof AppException){error_log('DNS operation '.$op['id'].' '.get_class($e).' '.$e->getMessage());}
            $this->db->run('UPDATE dns_operations SET status=?,attempts=?,last_error=?,run_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND) WHERE id=?',[$retry?'waiting':($e instanceof AppException&&$e->status<500?'needs_attention':'failed'),$attempt,$message,max(min(300,10*2**min($attempt,5)),$e instanceof AppException?($e->retryAfter??0):0),$op['id']]);
            (new Audit($this->db))->write('worker','dns.error','DNS 任务 #'.$op['id'].'：'.$message);return true;
        }finally{$this->db->value('SELECT RELEASE_LOCK(?)',[$lock]);}
    }
    private function matchesChanges(array $record,array $changes):bool
    {
        foreach($changes as $key=>$value){if($key==='content'&&in_array($record['type'],['A','AAAA'],true)){if(@inet_pton($record[$key])!==@inet_pton($value)){return false;}}
            elseif(($record[$key]??null)!=$value){return false;}}return true;
    }
    private function matchesIntent(array $record,array $before,array $changes):bool
    {
        $expected=array_replace($before,$changes);
        if(isset($changes['data'])){$expected['content']='';$record['content']='';}
        if(isset($changes['type'])&&$changes['type']!==$before['type']){foreach(['priority','data'] as $field){if(!array_key_exists($field,$changes)){unset($expected[$field],$record[$field]);}}}
        foreach([&$expected,&$record] as &$row){if(array_key_exists('comment',$row)&&$row['comment']===''){$row['comment']=null;}}unset($row);
        return ObservedDns::fingerprint([$record])===ObservedDns::fingerprint([$expected]);
    }
    private function complete(array $op,?array $record):void
    {
        $this->db->transaction(function()use($op,$record):void{
            $cache=$this->db->one('SELECT records_json FROM zone_dns_snapshots WHERE zone_ref=? FOR UPDATE',[$op['zone_ref']]);
            if($cache){$rows=array_values(array_filter(json_decode($cache['records_json'],true),fn($r)=>$r['id']!==$op['record_id']));if($record){$rows[]=$record;}(new ZoneDns($this->db))->store((int)$op['zone_ref'],$rows);}
            $this->db->run("UPDATE dns_operations SET status='completed',last_error=NULL WHERE id=?",[$op['id']]);
            (new Audit($this->db))->write('worker','dns.completed','原 DNS '.$op['action'].' 已读回确认：'.$op['hostname'].'，任务 #'.$op['id']);
        });
    }
    private function preferred(array $op,array $zone,array $options,array $before):bool
    {
        $preference=new DnsPreference($this->db,$this->cloud);
        if(!$op['domain_id']){
            ObservedDns::assertSame($before,$this->currentSet($zone,$op['hostname']),$op['hostname']);
            $result=$this->db->transaction(function()use($preference,$zone,$options,$op){$result=$preference->start($zone,$options['selected_record'],$options,$op['actor']);$this->db->run("UPDATE dns_operations SET domain_id=?,job_id=?,status='waiting_domain',run_at=UTC_TIMESTAMP() WHERE id=?",[$result['domain_id'],$result['job_id'],$op['id']]);return $result;});
            return true;
        }
        $domain=$this->db->one('SELECT * FROM domains WHERE id=? AND deleted_at IS NULL',[$op['domain_id']]);if(!$domain){throw new AppException('优选转换的本地记录不存在',409);}
        if($op['job_id']){
            $job=$this->db->one('SELECT * FROM jobs WHERE id=?',[$op['job_id']]);
            if(in_array($job['status'],['failed','needs_attention','paused','cancelled'],true)){throw new AppException('优选任务需处理：'.($job['last_error']??$job['status']),409);}
            if($job['status']!=='completed'){$this->db->run("UPDATE dns_operations SET run_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 3 SECOND) WHERE id=?",[$op['id']]);return false;}
        }
        if($domain['status']!=='active'){throw new AppException('原有 SaaS 检查未就绪，原解析保持，需先处理检查结果',409);}
        $changed=$this->db->transaction(fn()=>$preference->configure((int)$domain['id'],$options,$op['actor']));
        if(!empty($changed['job_id'])){$this->db->run("UPDATE dns_operations SET job_id=?,run_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 3 SECOND) WHERE id=?",[$changed['job_id'],$op['id']]);return true;}
        // Refresh after the delegated domain task, while already holding this zone's DNS lock.
        $rows=$this->cloud->client((int)$zone['account_id'])->pages('/zones/'.$zone['cf_id'].'/dns_records');(new ZoneDns($this->db))->store((int)$zone['id'],$rows);
        $this->db->run("UPDATE dns_operations SET status='completed',last_error=NULL WHERE id=?",[$op['id']]);
        (new Audit($this->db))->write('worker','dns.preferred_completed','原有 DNS 已切换优选并验证：'.$op['hostname'],(int)$domain['id'],$op['job_id']?(int)$op['job_id']:null);return true;
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);
namespace Panel\Services;

use Panel\Core\{Database,Validator,AppException};

/** Creates local management snapshots, with GET-only revalidation of pre-existing resources. */
final class SaasAdoption
{
    public function __construct(private Database $db,private Cloud $cloud,private Configuration $configuration) {}
    public function adopt(int $runId,array $input,string $actor):array
    {
        $preview=Validator::boolean($input['preview']??true);
        try{return $this->db->transaction(function()use($runId,$input,$actor,$preview):array{
            $run=$this->db->one('SELECT * FROM discovery_runs WHERE id=?',[$runId]);
            if(!$run||$run['state']!=='completed'){throw new AppException('请先完成本批次读取',409);}
            $inventory=new SaasInventory($this->db,$this->cloud);$inventory->assertMode($run);
            if((int)$this->db->value('SELECT TIMESTAMPDIFF(SECOND,created_at,UTC_TIMESTAMP()) FROM discovery_runs WHERE id=?',[$runId])>86400){throw new AppException('读取快照已超过 24 小时，请重新读取',409);}
            if(!is_string($input['key']??null)||!preg_match('/^[a-f0-9]{64}$/D',$input['key'])){throw new AppException('请选择有效的读取条目');}
            $stored=$this->db->value('SELECT payload_json FROM discovery_items WHERE run_id=? AND item_key=?',[$runId,$input['key']]);
            if(!$stored){throw new AppException('读取条目不存在',404);}$item=json_decode($stored,true);
            if($item['blockers']){throw new AppException(implode('；',$item['blockers']));}
            $routes=array_values(array_filter($item['routes'],fn($r)=>!$r['blockers']));$choice=$input['route_id']??(count($routes)===1?$routes[0]['id']:null);
            $routes=array_values(array_filter($routes,fn($r)=>$r['id']===$choice));if(count($routes)!==1){throw new AppException('请选择一个有效的 DNS 关联方案');}$route=$routes[0];
            if(!$route['known']&&!Validator::boolean($input['confirm_preferred']??false)){throw new AppException('此解析的优选用途尚未确认，请核对后勾选确认');}
            $standard=$input['cname_target']??$item['profile']['cname_target']??'';
            if(!is_string($standard)||($item['profile']['cname_candidates']?!in_array($standard,$item['profile']['cname_candidates'],true):$standard!=='')){throw new AppException('请选择读取到的标准 SaaS CNAME；没有候选时保留原优选配置');}
            if($standard===''&&($route['entry']['kind']??'standard')==='standard'){throw new AppException('标准接入模式缺少已读取的目标，不能猜测或创建解析');}
            if(isset($route['standard_target'])&&$route['standard_target']!==$standard){$route['entry']=['kind'=>'cname','targets'=>[$route['standard_target']]];}
            $links=new InventoryLinks($this->db,$runId);$zone=$links->zones[$item['zone_ref']];$dnsZone=$links->zones[$route['dns_zone_ref']];$origin=$item['origin'];
            foreach(array_unique([$zone['zone_ref'],$dnsZone['zone_ref'],$origin['zone_ref'],...array_column($route['baselines'],'zone_ref')]) as $ref){(new ZoneRecords($this->db))->zone((int)$ref,true);}
            $remote=$item['remote'];
            if(!$preview){
                $fresh=$inventory->currentHostname($zone,$remote['id']);
                foreach(['id','hostname','custom_origin_server','custom_origin_sni'] as $field){if($fresh[$field]!==$remote[$field]){throw new AppException('SaaS 域名或回源已变化，请重新读取后确认',409,'inventory_stale');}}$remote=$fresh;
                $fallback=$inventory->readPage($zone,'fallback',1)['result']??[];
                if(($fallback['origin']??'')!==$item['profile']['fallback_origin']){throw new AppException('SaaS 默认回源已变化，请重新读取',409,'inventory_stale');}
                if($standard!==''){
                    $standardRecords=$inventory->currentRecords($zone,$standard);
                    if(!array_filter($standardRecords,fn($r)=>$r['type']==='CNAME'&&!empty($r['proxied'])&&strtolower(rtrim($r['content'],'.'))===$item['profile']['fallback_origin'])){throw new AppException('标准 CNAME 已变化，请重新读取',409,'inventory_stale');}
                }
                ObservedDns::assertSame($route['records'],$inventory->currentRecords($dnsZone,$item['hostname']),$item['hostname']);
                foreach($route['baselines'] as $baseline){ObservedDns::assertSame($baseline['records'],$inventory->currentRecords($links->zones[$baseline['zone_ref']],$baseline['hostname']),$baseline['hostname']);}
                ObservedDns::assertSame($origin['records'],$inventory->currentRecords($links->zones[$origin['zone_ref']],$origin['hostname']),$origin['hostname']);
            }
            $existing=$this->db->one('SELECT * FROM domains WHERE hostname=? FOR UPDATE',[$item['hostname']]);
            if($existing){
                $applied=(new DomainConfig($this->db))->applied($existing);
                $same=!$existing['deleted_at']&&$existing['cf_hostname_id']===$remote['id']&&(int)$applied['profile']['account_id']===$item['account_id']&&$applied['profile']['zone_id']===$zone['zone_id']&&(int)$existing['dns_account_id']===(int)$dnsZone['account_id']&&$existing['dns_zone_id']===$dnsZone['zone_id']&&$applied['origin']['hostname']===$origin['hostname']&&DnsMode::preferred($applied);
                $same=$same&&$applied['origin']['mode']===$origin['mode']&&$applied['entry']['kind']===$route['entry']['kind']&&ObservedDns::sameTargets(json_decode($applied['entry']['targets'],true),$route['entry']['targets'])&&($applied['entry']['hostname']??null)===($route['entry']['hostname']??null);
                if(!empty($route['entry']['hostname'])){$owner=$links->zones[$route['entry']['zone_ref']];$same=$same&&(int)$applied['entry']['dns_account_id']===(int)$owner['account_id']&&$applied['entry']['dns_zone_id']===$owner['zone_id'];}
                if(!empty($applied['origin']['external_dns'])){$same=$same&&ObservedDns::fingerprint($applied['origin']['external_dns']['records'])===ObservedDns::fingerprint($origin['records']);}
                elseif(!empty($applied['origin']['source_ip'])||!empty($applied['origin']['source_hostname'])){$same=$same&&ObservedDns::sameTargets([$applied['origin']['source_ip']??$applied['origin']['source_hostname']],$origin['targets']);}
                if($same){$desired=DnsMode::desired($existing,$applied);$addresses=array_values(array_filter($route['records'],fn($r)=>in_array($r['type'],['A','AAAA','CNAME'],true)));$same=count($desired)===count($addresses);foreach($desired as $want){$same=$same&&(bool)array_filter($addresses,fn($r)=>DnsChanges::same($r,$want));}}
                if(!$same){throw new AppException('本地已有不同配置或归档历史，请在域名管理中处理，纳管不会覆盖',409);}
                $result=['domain_id'=>(int)$existing['id'],'reused'=>true,'hostname'=>$item['hostname'],'job_id'=>null,'message'=>'已有相同配置，复用本地记录'];
                if($preview){throw new AdoptionPreview($result);}return $result+['preview'=>false];
            }
            $profile=$this->db->one('SELECT * FROM profiles WHERE account_id=? AND zone_id=? AND deleted_at IS NULL',[$zone['account_id'],$zone['zone_id']]);
            if($profile&&(!$profile['active']||$profile['cname_target']!==$standard||$profile['fallback_origin']!==$item['profile']['fallback_origin'])){throw new AppException('本地该 SaaS 站点的服务设置不同或已停用，请先核对',409);}
            if($profile){$profileId=(int)$profile['id'];}
            elseif($standard!==''){$profileId=$this->configuration->save('profiles',['name'=>mb_substr($zone['zone_name'].' SaaS',0,100),'account_id'=>(int)$zone['account_id'],'zone_id'=>$zone['zone_id'],'cname_target'=>$standard,'fallback_origin'=>$item['profile']['fallback_origin']],$actor);}
            else{
                $profileId=$this->db->insert('INSERT INTO profiles(name,account_id,zone_id,zone_name,cname_target,fallback_origin) VALUES(?,?,?,?,?,?)',[mb_substr($zone['zone_name'].' SaaS',0,100),$zone['account_id'],$zone['zone_id'],$zone['zone_name'],'',$item['profile']['fallback_origin']]);
                $this->db->run("INSERT INTO origins(name,profile_id,mode,hostname) VALUES(?,?,'fallback',?)",[mb_substr($zone['zone_name'].' · 默认回源',0,100),$profileId,$item['profile']['fallback_origin']]);
                (new Audit($this->db))->write($actor,'profile.observed','保存已有 SaaS 站点，保留原优选配置，未补建标准 CNAME');
            }
            $entry=$route['entry'];$entryId=null;
            foreach($this->db->all('SELECT * FROM entries WHERE kind=? AND active=1 AND deleted_at IS NULL',[$entry['kind']]) as $candidate){if(ObservedDns::sameTargets(json_decode($candidate['targets'],true),$entry['targets'])&&($candidate['hostname']??null)===($entry['hostname']??null)&&(!isset($entry['hostname'])||(int)$candidate['zone_ref']===(int)$entry['zone_ref'])){$entryId=(int)$candidate['id'];$entry['targets']=json_decode($candidate['targets'],true);break;}}
            if($entryId===null){$fields=isset($entry['hostname'])?['zone_id'=>(int)$entry['zone_ref'],'hostname'=>$entry['hostname']]:[];$entryId=$this->configuration->save('entries',['name'=>mb_substr($entry['hostname']??($entry['kind']==='standard'?'已有标准接入':implode(' / ',$entry['targets'])),0,100),'kind'=>$entry['kind'],'targets'=>$entry['targets']]+$fields,$actor);}
            if(!empty($entry['hostname'])){
                $entryZone=$links->zones[$entry['zone_ref']];$entryRecords=$route['baselines'][0]['records'];$want=Validator::json(EntryAlias::desired($entry));$saved=$this->db->one('SELECT * FROM entry_routes WHERE entry_id=?',[$entryId]);
                if($saved){if($saved['state']!=='applied'||$saved['desired_json']!==$want){throw new AppException('本地共享入口存在其他部署计划，请先处理',409);}ObservedDns::assertSame(json_decode($saved['records_json'],true),$entryRecords,$entry['hostname']);}
                else{$this->db->run("INSERT INTO entry_routes(entry_id,account_id,zone_id,hostname,desired_json,records_json,state) VALUES(?,?,?,?,?,?,'applied')",[$entryId,$entryZone['account_id'],$entryZone['zone_id'],$entry['hostname'],$want,Validator::json($entryRecords)]);}
            }
            $sourceZone=$links->zones[$origin['zone_ref']];$sourceBaseline=['account_id'=>(int)$sourceZone['account_id'],'zone_id'=>$sourceZone['zone_id'],'records'=>$origin['records']];
            $originId=$this->configuration->save('origins',['name'=>mb_substr($item['hostname'].' · 已有回源',0,100),'profile_id'=>$profileId,'mode'=>$origin['mode'],'hostname'=>$origin['hostname']],$actor);
            $scalar=count($origin['targets'])===1?$origin['targets'][0]:null;$sourceIp=$origin['kind']==='ip'?$scalar:null;$sourceHostname=$origin['kind']==='cname'?$scalar:null;
            $this->db->run('UPDATE origins SET source_ip=?,source_hostname=?,external_dns_json=? WHERE id=?',[$sourceIp,$sourceHostname,Validator::json($sourceBaseline),$originId]);
            $config=(new DomainConfig($this->db))->capture(['profile_id'=>$profileId,'entry_id'=>$entryId,'origin_id'=>$originId]);$config['dns_mode']='preferred';
            $config['observed_dns']=[['account_id'=>(int)$dnsZone['account_id'],'zone_id'=>$dnsZone['zone_id'],'hostname'=>$item['hostname'],'records'=>$route['records']]];
            foreach($route['baselines'] as $baseline){$owner=$links->zones[$baseline['zone_ref']];$config['observed_dns'][]=['account_id'=>(int)$owner['account_id'],'zone_id'=>$owner['zone_id'],'hostname'=>$baseline['hostname'],'records'=>$baseline['records']];}
            (new EntryAlias($this->db))->assertBusiness($item['hostname'],$config);(new DomainConfig($this->db))->assertAvailable($config,(int)$dnsZone['account_id']);
            $domainId=$this->db->insert("INSERT INTO domains(hostname,profile_id,dns_account_id,dns_zone_id,entry_id,origin_id,adopted,cf_hostname_id,hostname_created,hostname_status,ssl_status,cleanup_status,remote_json,applied_config) VALUES(?,?,?,?,?,?,1,?,0,?,?,'not_managed',?,?)",[$item['hostname'],$profileId,$dnsZone['account_id'],$dnsZone['zone_id'],$entryId,$originId,$remote['id'],$remote['status'],$remote['ssl']['status'],Validator::json($remote),Validator::json($config)]);
            $jobId=(new DomainService($this->db))->queue($domainId,'inspect','inspect');
            $resolution=['inventory_run_id'=>$runId,'route_id'=>$route['id'],'preferred_confirmed'=>true,'confirmation_source'=>$route['known']?'automatic':'manual','cname_target'=>$standard];
            $this->db->run('UPDATE jobs SET context_json=? WHERE id=?',[Validator::json($resolution),$jobId]);
            $defaults=(new Settings($this->db))->read();
            if(!$defaults['profile_id']){
                $shared=$this->db->value("SELECT id FROM origins WHERE profile_id=? AND mode='fallback' AND source_ip IS NULL AND source_hostname IS NULL AND external_dns_json IS NULL AND active=1 AND deleted_at IS NULL LIMIT 1",[$profileId]);
                (new Settings($this->db))->save(['profile_id'=>$profileId,'origin_id'=>$shared?:null,'dns_account_id'=>$defaults['dns_account_id']?:$dnsZone['account_id'],'entry_id'=>$defaults['entry_id']?:$entryId],$actor);
            }
            $result=['hostname'=>$item['hostname'],'domain_id'=>$domainId,'job_id'=>$jobId,'reused'=>false,'profile_id'=>$profileId,'entry_id'=>$entryId,'origin_id'=>$originId,'chain'=>$route['chain'],'origin'=>$origin['hostname'],'source_targets'=>$origin['targets'],'message'=>'保留当前 DNS 与回源，纳管后执行只读检查'];
            if($preview){throw new AdoptionPreview($result);}
            (new Audit($this->db))->write($actor,'inventory.adopted','从批次 #'.$runId.' 纳入已有 SaaS：'.$item['hostname'].'；现有云端资源按外部归属保留；确认方案：'.Validator::json($resolution),$domainId,$jobId);
            return $result+['preview'=>false];
        });}catch(AdoptionPreview $preview){return $preview->result+['preview'=>true];}
    }
}

final class AdoptionPreview extends \RuntimeException
{
    public function __construct(public readonly array $result){parent::__construct('Local adoption preview rolled back');}
}

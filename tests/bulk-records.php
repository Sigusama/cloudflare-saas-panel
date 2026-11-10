<?php
// SPDX-License-Identifier: AGPL-3.0-only
use Panel\Services\{BulkRecords,DnsChanges,Audit};
use Panel\Core\Validator;

$db->run("UPDATE jobs SET status='cancelled' WHERE status NOT IN ('completed','cancelled')");$db->run('UPDATE domains SET next_check_at=NULL');
$bulk=new BulkRecords($db);
$bulkBase=['profile_id'=>$profile,'entry_id'=>$modeEntry,'source_ip'=>'9.9.9.50','type'=>'A','dns_mode'=>'dns_only','check'=>['path'=>'/','statuses'=>[200],'keyword'=>'']];
$bulkIds=[];
foreach (['a'=>'dns_only','b'=>'proxied','c'=>'dns_only'] as $name=>$mode) { $row=$zoneRecords->create($customerZone,[...$bulkBase,'name'=>'bulk-'.$name,'dns_mode'=>$mode],'test');$bulkIds[$name]=$row['items'][0]['domain_id']; }
tick($worker,$db,9);
$preset=$zoneRecords->create($customerZone,['name'=>'bulk-preset','type'=>'preset','origin_id'=>$origin,'profile_id'=>$profile,'entry_id'=>$modeEntry,'check'=>['path'=>'/','statuses'=>[200],'keyword'=>'']],'test');$presetId=$preset['items'][0]['domain_id'];tick($worker,$db,6);
$foreign=$zoneRecords->create($secondZone,[...$bulkBase,'name'=>'bulk-foreign'],'test')['items'][0]['domain_id'];
$queued=$zoneRecords->create($customerZone,[...$bulkBase,'name'=>'bulk-queued'],'test')['items'][0]['domain_id'];
$records=array_map(fn($id)=>['id'=>(int)$id,'expected_version'=>1],[...array_values($bulkIds),$presetId,$foreign,$queued,9999999]);
$bulkInput=['action'=>'set_mode','dns_mode'=>'proxied','records'=>$records,'preview'=>true];
$counts=$db->one('SELECT (SELECT COUNT(*) FROM jobs) AS jobs,(SELECT COUNT(*) FROM domain_changes) AS changes,(SELECT COUNT(*) FROM audit_logs) AS logs');
$beforeCloud=count($dns->requests)+count($saas->requests);
$preview=$bulk->execute($customerZone,$bulkInput,'test');
check($preview['summary']['ready']===2&&$preview['summary']['unchanged']===1&&$preview['summary']['failed']===4,'bulk preview separates ready, unchanged, wrong-Zone, pending, missing-source and missing records');
check($counts===$db->one('SELECT (SELECT COUNT(*) FROM jobs) AS jobs,(SELECT COUNT(*) FROM domain_changes) AS changes,(SELECT COUNT(*) FROM audit_logs) AS logs')&&$beforeCloud===count($dns->requests)+count($saas->requests),'bulk preview leaves no jobs, changes, audit writes or provider calls');
check(array_values(array_filter($preview['items'],fn($r)=>$r['id']===$foreign))[0]['hostname']===null,'bulk scope validation does not disclose foreign-Zone hostnames');
$actual=[...$bulkInput,'preview'=>false];
$submitted=$auth->idempotent('api:bulk-test','bulk-mode',$actual,fn()=>$bulk->execute($customerZone,$actual,'test'),'POST /api/v1/zones/'.$customerZone.'/records/bulk');
check($submitted['summary']['queued']===2&&$submitted['summary']['unchanged']===1&&$submitted['summary']['failed']===4,'bulk submit isolates per-record failures without blocking valid records');
$jobsAfter=(int)$db->value('SELECT COUNT(*) FROM jobs');
check($auth->idempotent('api:bulk-test','bulk-mode',$actual,fn()=>throw new RuntimeException('must replay'),'POST /api/v1/zones/'.$customerZone.'/records/bulk')===$submitted&&(int)$db->value('SELECT COUNT(*) FROM jobs')===$jobsAfter,'bulk idempotency returns the original per-record results without duplicate jobs');
rejects(fn()=>$auth->idempotent('api:bulk-test','bulk-mode',[...$actual,'dns_mode'=>'preferred'],fn()=>[],'POST /api/v1/zones/'.$customerZone.'/records/bulk'),409,'bulk idempotency rejects a different target');
$service->action($foreign,'cancel','test');$service->action($queued,'cancel','test');tick($worker,$db,6);
check($service->detail($bulkIds['a'])['dns_mode']==='proxied'&&$service->detail($bulkIds['c'])['dns_mode']==='proxied'&&(int)$service->detail($bulkIds['b'])['config_version']===1,'accepted bulk mode jobs execute independently and unchanged records keep their revision');
$jobId=array_values(array_filter($submitted['items'],fn($r)=>isset($r['job_id'])))[0]['job_id'];
check(json_decode($db->value('SELECT context_json FROM jobs WHERE id=?',[$jobId]),true)['batch_id']===$submitted['batch_id']&&(int)$db->value("SELECT COUNT(*) FROM audit_logs WHERE event='bulk.submitted'")===1,'bulk jobs and audit records share a batch identifier');
$partial=$bulk->execute($customerZone,['action'=>'set_mode','dns_mode'=>'dns_only','preview'=>false,'records'=>[['id'=>$bulkIds['a'],'expected_version'=>1],['id'=>$bulkIds['c'],'expected_version'=>2]]],'test');
check($partial['summary']['failed']===1&&$partial['summary']['queued']===1&&$partial['items'][0]['error']['code']==='version_conflict','bulk optimistic versions reject stale rows while current rows still queue');tick($worker,$db,3);
rejects(fn()=>$bulk->execute($customerZone,[...$bulkInput,'records'=>array_fill(0,51,['id'=>1,'expected_version'=>1])],'test'),422,'bulk limit is fifty explicit records');
rejects(fn()=>$bulk->execute($customerZone,[...$bulkInput,'records'=>[['id'=>$bulkIds['a'],'expected_version'=>2],['id'=>$bulkIds['a'],'expected_version'=>2]]],'test'),422,'duplicate record identifiers reject the request before mutation');
rejects(fn()=>$bulk->execute($customerZone,[...$bulkInput,'records'=>[['id'=>(string)$bulkIds['a'],'expected_version'=>2]]],'test'),422,'bulk identifiers must be strict integers');
rejects(fn()=>$bulk->execute($customerZone,[...$bulkInput,'records'=>[['id'=>$bulkIds['a']]]],'test'),422,'bulk records require an explicit expected version');
rejects(fn()=>$bulk->execute($customerZone,[...$bulkInput,'action'=>'purge_all'],'test'),422,'bulk action cannot target an implicit wildcard');
rejects(fn()=>$bulk->execute($customerZone,[...$bulkInput,'preview'=>'false'],'test'),422,'string false cannot authorize bulk submission');

// Deleting current records is distinct from restoring their previous DNS.
$dns->records['bulk-original']=['id'=>'bulk-original','name'=>'bulk-delete.customer.test','type'=>'A','content'=>'8.8.8.8','proxied'=>false,'ttl'=>300,'comment'=>'original service'];
$dns->records['bulk-unrelated-txt']=['id'=>'bulk-unrelated-txt','name'=>'bulk-delete.customer.test','type'=>'TXT','content'=>'preserve me','ttl'=>300];
$deleteId=$zoneRecords->create($customerZone,[...$bulkBase,'name'=>'bulk-delete','replace_existing'=>true],'test')['items'][0]['domain_id'];tick($worker,$db,3);
$ownedId=$zoneRecords->create($customerZone,[...$bulkBase,'name'=>'bulk-owned','dns_mode'=>'preferred'],'test')['items'][0]['domain_id'];tick($worker,$db,6);
$owned=$service->detail($ownedId);$ownedHost=$owned['cf_hostname_id'];$ownedAlias=$owned['applied_config']['origin']['hostname'];
(new DnsChanges($db,new Audit($db)))->ensureTxt($owned,$owned['jobs'][0],$cloud->dns($account2),'zone-customer','_cf-custom-hostname.bulk-owned.customer.test','delete-test-token','ownership');
$saas->hostnames['bulk-reused-host']=['id'=>'bulk-reused-host','hostname'=>'bulk-reused.customer.test'];
$reusedId=$zoneRecords->create($customerZone,['name'=>'bulk-reused','type'=>'preset','origin_id'=>$origin,'profile_id'=>$profile,'entry_id'=>$modeEntry,'check'=>['path'=>'/','statuses'=>[200],'keyword'=>'']],'test')['items'][0]['domain_id'];tick($worker,$db,6);
$deleteInput=['action'=>'delete','records'=>array_map(fn($id)=>['id'=>$id,'expected_version'=>1],[$deleteId,$ownedId,$reusedId]),'preview'=>true];
$beforeDeleteCloud=Validator::json([$dns->records,$saas->hostnames]);$beforeDeleteJobs=(int)$db->value('SELECT COUNT(*) FROM jobs');
$deletePreview=$bulk->execute($customerZone,$deleteInput,'test');
check($deletePreview['summary']['ready']===3&&$beforeDeleteCloud===Validator::json([$dns->records,$saas->hostnames])&&(int)$db->value('SELECT COUNT(*) FROM jobs')===$beforeDeleteJobs,'bulk deletion preview performs no provider changes or lasting queue writes');
$deleteSubmit=$bulk->execute($customerZone,[...$deleteInput,'preview'=>false],'test');check($deleteSubmit['summary']['queued']===3,'bulk deletion returns jobs rather than claiming cloud records are already deleted');tick($worker,$db,3);
check(!array_filter($dns->records,fn($r)=>$r['name']==='bulk-delete.customer.test'&&in_array($r['type'],['A','AAAA','CNAME'],true))&&isset($dns->records['bulk-unrelated-txt']),'current-record deletion removes address DNS without restoring old A or deleting unrelated TXT');
check(!isset($saas->hostnames[$ownedHost])&&!array_filter($saas->records,fn($r)=>$r['name']===$ownedAlias)&&$service->detail($ownedId)['deleted_at']!==null,'bulk deletion cleans owned SaaS hostname and its managed source aliases');
check(!array_filter($dns->records,fn($r)=>$r['name']==='_cf-custom-hostname.bulk-owned.customer.test')&&isset($saas->hostnames['bulk-reused-host']),'bulk deletion cleans owned validation TXT while preserving a reused cloud hostname');

$externalId=$zoneRecords->create($customerZone,[...$bulkBase,'name'=>'bulk-external'],'test')['items'][0]['domain_id'];tick($worker,$db,3);
foreach($dns->records as &$record){if($record['name']==='bulk-external.customer.test'&&$record['type']==='A')$record['content']='8.8.4.4';}unset($record);
$bulk->execute($customerZone,['action'=>'delete','preview'=>false,'records'=>[['id'=>$externalId,'expected_version'=>1],['id'=>$bulkIds['b'],'expected_version'=>1]]],'test');tick($worker,$db,2);
check($service->detail($externalId)['deleted_at']===null&&$service->detail($externalId)['status']==='needs_attention'&&array_values(array_filter($dns->records,fn($r)=>$r['name']==='bulk-external.customer.test'))[0]['content']==='8.8.4.4','bulk delete worker preserves externally modified DNS and keeps the failed record managed');
check($service->detail($bulkIds['b'])['deleted_at']!==null,'one deletion job failure does not block another selected record');
$beforeForgetCalls=count($saas->requests)+count($dns->requests);
$forgot=$bulk->execute($customerZone,['action'=>'delete','mode'=>'forget','preview'=>false,'records'=>[['id'=>$queued,'expected_version'=>1]]],'test');
check($forgot['summary']['removed']===1&&$beforeForgetCalls===count($saas->requests)+count($dns->requests),'bulk forget removes local management without provider calls');
rejects(fn()=>$service->remove($bulkIds['a'],['mode'=>'delete_records','expected_version'=>1],'test'),409,'single-record deletion also enforces expected configuration version');
$restoreName='bulk-restore.customer.test';$dns->records['bulk-restore-original']=['id'=>'bulk-restore-original','name'=>$restoreName,'type'=>'A','content'=>'8.8.8.8','proxied'=>false,'ttl'=>300,'comment'=>'restore original'];
$restoreId=$zoneRecords->create($customerZone,[...$bulkBase,'name'=>'bulk-restore','replace_existing'=>true],'test')['items'][0]['domain_id'];tick($worker,$db,3);
$bulk->execute($customerZone,['action'=>'delete','mode'=>'restore','preview'=>false,'records'=>[['id'=>$restoreId,'expected_version'=>1]]],'test');tick($worker,$db);
check(array_values(array_filter($dns->records,fn($r)=>$r['name']===$restoreName))[0]['content']==='8.8.8.8','bulk restore mode retains the existing restore-original-DNS behavior');

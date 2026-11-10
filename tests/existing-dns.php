<?php
// SPDX-License-Identifier: AGPL-3.0-only
use Panel\Services\{ZoneDns, ZoneRecords, SaasInventory, SaasAdoption, ObservedDns, Transfer};
use Panel\Core\{Validator, AppException};

$db->run("UPDATE jobs SET status='cancelled' WHERE status NOT IN ('completed','cancelled')");$db->run('UPDATE domains SET next_check_at=NULL');
$existingOnly=new FakeCloudflare('existing-only-zone','existing-only.test');$existingOnly->ready=true;$existingOnly->preserveHostStatus=true;
$existingOnly->fallbackOrigin=['origin'=>'origin.existing-only.test','status'=>'active'];
$existingOnlyId=$configs->save('accounts',['name'=>'Existing-only SaaS','token'=>str_repeat('x',40)],'test');$clients[$existingOnlyId]=$existingOnly;$configs->sync($existingOnlyId);
$existingOnlyZone=(int)$db->value('SELECT id FROM zones WHERE account_id=?',[$existingOnlyId]);
$existingOnly->records=[
 'origin'=>['id'=>'origin','name'=>'origin.existing-only.test','type'=>'A','content'=>'9.9.9.41','proxied'=>true,'ttl'=>1],
 'entry'=>['id'=>'entry','name'=>'dns.existing-only.test','type'=>'CNAME','content'=>'preferred.external.test','proxied'=>false,'ttl'=>600,'comment'=>'operator entry','tags'=>['owner:external']],
 'mail'=>['id'=>'mail','name'=>'existing-only.test','type'=>'MX','content'=>'mail.external.test','priority'=>10,'ttl'=>3600],
 'caa'=>['id'=>'caa','name'=>'existing-only.test','type'=>'CAA','content'=>'0 issue example.test','data'=>['flags'=>0,'tag'=>'issue','value'=>'example.test'],'ttl'=>3600],
 'srv'=>['id'=>'srv','name'=>'_service._tcp.existing-only.test','type'=>'SRV','content'=>'0 5 443 service.external.test','data'=>['priority'=>0,'weight'=>5,'port'=>443,'target'=>'service.external.test'],'ttl'=>600],
 'https'=>['id'=>'https','name'=>'https.existing-only.test','type'=>'HTTPS','content'=>'1 . alpn="h2"','data'=>['priority'=>1,'target'=>'.','value'=>'alpn="h2"'],'ttl'=>600],
 'ipv6'=>['id'=>'ipv6','name'=>'ipv6.existing-only.test','type'=>'AAAA','content'=>'2606:4700::1111','ttl'=>600],
 'wildcard'=>['id'=>'wildcard','name'=>'*.existing-only.test','type'=>'A','content'=>'9.9.9.42','ttl'=>600]
];
foreach(['app','www'] as $name){$existingOnly->records[$name]=['id'=>$name,'name'=>$name.'.existing-only.test','type'=>'CNAME','content'=>'dns.existing-only.test','proxied'=>false,'ttl'=>300,'comment'=>'original business'];$existingOnly->hostnames[$name]=['id'=>$name,'hostname'=>$name.'.existing-only.test','status'=>'active','ssl'=>['status'=>'active'],'custom_origin_server'=>'origin.existing-only.test','custom_origin_sni'=>''];}
$existingOnly->records['same-owner-txt']=['id'=>'same-owner-txt','name'=>'app.existing-only.test','type'=>'TXT','content'=>'unrelated verification','ttl'=>600];
for($i=0;$i<105;$i++){$existingOnly->records['txt-'.$i]=['id'=>'txt-'.$i,'name'=>'txt-'.$i.'.existing-only.test','type'=>'TXT','content'=>'txt-value-'.$i,'ttl'=>3600];}
$originalDns=$existingOnly->records;$originalHosts=$existingOnly->hostnames;$existingWrites=$writeCount();
$cache=new ZoneDns($db);$dnsSync=$cache->sync($existingOnlyZone,$cloud,'test');
check($dnsSync['count']===count($originalDns)&&$writeCount()===$existingWrites,'all existing DNS types load across pages without any cloud write');
$cacheRecords=$cache->snapshot($existingOnlyZone)['records'];$cacheById=array_column($cacheRecords,null,'id');
check($cacheById['mail']['priority']===10&&$cacheById['caa']['data']['tag']==='issue'&&$cacheById['srv']['data']['port']===443&&$cacheById['https']['data']['priority']===1,'DNS cache preserves MX priority, CAA, SRV and HTTPS data');
$zonesService=new ZoneRecords($db);$existingRows=[...$zonesService->records($existingOnlyZone,1,100,'',true)['items'],...$zonesService->records($existingOnlyZone,2,100,'',true)['items']];
check(count($existingRows)===count($originalDns)&&count(array_unique(array_column($existingRows,'id')))===count($originalDns),'combined DNS list paginates every existing record exactly once');
check($zonesService->records($existingOnlyZone,1,100)['total']===0&&$zonesService->records($existingOnlyZone,1,100,'',true)['total']===count($originalDns),'legacy managed API remains compatible while full DNS list exposes unmanaged records');
check($zonesService->records($existingOnlyZone,1,100,'CAA',true)['items'][0]['record']['id']==='caa'&&$zonesService->records($existingOnlyZone,1,100,'txt-value-104',true)['total']===1,'all DNS records can be searched by type and content');
$existingOnly->readFailures['/zones/existing-only-zone/dns_records']=['times'=>1,'status'=>503,'page'=>2];
rejects(fn()=>$cache->sync($existingOnlyZone,$cloud,'test'),503,'partial DNS synchronization does not publish a truncated list');
check(ObservedDns::fingerprint($cache->snapshot($existingOnlyZone)['records'])===ObservedDns::fingerprint($cacheRecords),'failed synchronization retains the last complete DNS snapshot');$existingOnly->readFailures=[];
$existingReader=new SaasInventory($db,$cloud);$existingAdopter=new SaasAdoption($db,$cloud,$configs);
$existingScan=$completeInventory($existingReader,$existingReader->begin(['account_ids'=>[$existingOnlyId]],'test'));
$existingItem=$existingReader->items($existingScan['run']['id'],1,100,'app.existing-only.test')['items'][0];
check($existingItem['confidence']==='pending'&&!$existingItem['blockers']&&!$existingItem['profile']['cname_candidates'],'working SaaS with preferred CNAME and no standard orange CNAME is eligible for adoption');
$existingRequest=['key'=>$existingItem['key'],'confirm_preferred'=>true,'preview'=>false];
rejects(fn()=>$existingAdopter->adopt($existingScan['run']['id'],[...$existingRequest,'confirm_preferred'=>false],'test'),422,'unproven preferred purpose still requires confirmation without a standard CNAME');
rejects(fn()=>$existingAdopter->adopt($existingScan['run']['id'],[...$existingRequest,'cname_target'=>'invented.existing-only.test'],'test'),422,'standard-free adoption never accepts an invented bootstrap target');
$existingPreview=$existingAdopter->adopt($existingScan['run']['id'],[...$existingRequest,'preview'=>true],'test');
check($existingPreview['preview']&&!$db->value("SELECT id FROM profiles WHERE account_id=?",[$existingOnlyId]),'standard-free preview rolls back its local profile and creates no cloud records');
$existingOnly->records['app']['ttl']=1200;
rejects(fn()=>$existingAdopter->adopt($existingScan['run']['id'],$existingRequest,'test'),409,'standard-free adoption still rejects externally changed visitor DNS');$existingOnly->records=$originalDns;
$existingAdopted=$existingAdopter->adopt($existingScan['run']['id'],$existingRequest,'test');
$existingSecond=$existingReader->items($existingScan['run']['id'],1,100,'www.existing-only.test')['items'][0];
$existingAdopter->adopt($existingScan['run']['id'],['key'=>$existingSecond['key'],'confirm_preferred'=>true,'preview'=>false],'test');
check($db->value('SELECT cname_target FROM profiles WHERE id=?',[$existingAdopted['profile_id']])===''&&$existingOnly->records===$originalDns&&$existingOnly->hostnames===$originalHosts,'adoption preserves every original DNS attribute and remote hostname without fabricating a standard target');
$existingWorker=new \Panel\Jobs\Worker($db,$cloud,$fakeDiagnostics);tick($existingWorker,$db,2);
check($service->detail($existingAdopted['domain_id'])['status']==='active'&&$writeCount()===$existingWrites,'standard-free adopted SaaS passes GET-only origin, DNS and HTTPS inspection');
$merged=[...$zonesService->records($existingOnlyZone,1,100,'',true)['items'],...$zonesService->records($existingOnlyZone,2,100,'',true)['items']];$mergedIds=[];
foreach($merged as $row){foreach($row['managed']?($row['observed_dns_records']??[]):$row['dns_records'] as $rr){$mergedIds[]=$rr['id'];}}
sort($mergedIds);$originalIds=array_column($originalDns,'id');sort($originalIds);
check($mergedIds===$originalIds&&count(array_filter($merged,fn($r)=>$r['managed']))===2,'after adoption every DNS ID is retained exactly once, including TXT beside a managed business name');
rejects(fn()=>$service->create(['hostnames'=>['new.existing-only.test'],'profile_id'=>$existingAdopted['profile_id'],'dns_account_id'=>$existingOnlyId,'entry_id'=>$existingAdopted['entry_id'],'origin_id'=>$existingAdopted['origin_id']],'test'),422,'missing bootstrap CNAME cannot accidentally re-provision an existing-only profile');
$configs->update('profiles',$existingAdopted['profile_id'],['name'=>'Existing-only renamed'],'test');
check($db->value('SELECT cname_target FROM profiles WHERE id=?',[$existingAdopted['profile_id']])==='','editing an observed profile name preserves its intentionally empty bootstrap target');
$existingDoc=(new Transfer($db,$configs))->export($existingOnlyZone);$existingBindings=[];foreach($existingDoc['accounts'] as $a){$existingBindings[$a['ref']]=(int)$a['ref'];}
check($existingDoc['format_version']===5&&(new Transfer($db,$configs))->import(['document'=>$existingDoc,'account_bindings'=>$existingBindings,'preview'=>true],'test')['reused']===2,'format five roundtrip reuses adopted existing-only SaaS without creating bootstrap DNS');
check($existingOnly->records===$originalDns&&$existingOnly->hostnames===$originalHosts&&$writeCount()===$existingWrites,'all DNS loading, adoption, inspection and export leave original Cloudflare configuration unchanged');

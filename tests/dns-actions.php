<?php
// SPDX-License-Identifier: AGPL-3.0-only
use Panel\Services\{BulkRecords,DnsOperations,ZoneDns,ObservedDns,DomainConfig,DnsMode};
use Panel\Core\Validator;

$db->run("DELETE FROM settings WHERE name='preview_mode'");$db->run("UPDATE jobs SET status='cancelled' WHERE status NOT IN ('completed','cancelled')");$db->run('UPDATE domains SET next_check_at=NULL');
$actions=new BulkRecords($db,$cloud);$operations=new DnsOperations($db,$cloud);$actionCache=new ZoneDns($db);$actionWorker=new \Panel\Jobs\Worker($db,$cloud,$fakeDiagnostics);
$rawRef=function(string $id)use($actionCache,$existingOnlyZone):array{foreach($actionCache->snapshot($existingOnlyZone)['records'] as $row){if($row['id']===$id){return ['id'=>'cf:'.$id,'expected_fingerprint'=>ObservedDns::fingerprint([$row])];}}throw new RuntimeException('Missing raw reference '.$id);};
$stepActions=function(int $count=1)use($db,$actionWorker):void{for($n=0;$n<$count;$n++){$db->run("UPDATE dns_operations SET run_at=UTC_TIMESTAMP() WHERE status IN ('queued','waiting','waiting_domain')");$db->run("UPDATE jobs SET run_at=UTC_TIMESTAMP() WHERE status IN ('queued','waiting')");$actionWorker->once();}};
$finishAction=function(int $id)use($stepActions,$operations):array{for($n=0;$n<50;$n++){$row=$operations->detail($id);if($row['status']==='completed'){return $row;}if(in_array($row['status'],['failed','needs_attention'],true)){throw new RuntimeException('DNS action failed: '.$row['last_error']);}$stepActions();}throw new RuntimeException('DNS action did not complete');};
$writesBefore=$writeCount();$opsBefore=(int)$db->value('SELECT COUNT(*) FROM dns_operations');$mxOriginal=$existingOnly->records['mail'];
$mxEdit=['action'=>'update','records'=>[[...$rawRef('mail'),'changes'=>['content'=>'new-mail.external.test','ttl'=>600,'priority'=>25]]],'preview'=>true];
$mxPreview=$actions->execute($existingOnlyZone,$mxEdit,'test');
check($mxPreview['summary']['ready']===1&&$mxPreview['items'][0]['before'][0]['priority']===10&&$mxPreview['items'][0]['after'][0]['priority']===25,'MX editing previews the exact original and intended priority');
check($writeCount()===$writesBefore&&$existingOnly->records['mail']===$mxOriginal&&(int)$db->value('SELECT COUNT(*) FROM dns_operations')===$opsBefore,'preview creates no mutation intent and preserves original DNS byte-for-byte');
$mxQueued=$actions->execute($existingOnlyZone,[...$mxEdit,'preview'=>false],'test')['items'][0];
check($mxQueued['status']==='queued'&&$existingOnly->records['mail']===$mxOriginal&&$writeCount()===$writesBefore,'explicit submission only queues DNS work until the executor runs');
$stepActions();
check($operations->detail($mxQueued['operation_id'])['status']==='completed'&&$existingOnly->records['mail']['id']==='mail'&&$existingOnly->records['mail']['priority']===25&&$existingOnly->records['mail']['ttl']===600,'submitted MX edit updates its existing record ID and reads it back');
check(isset($existingOnly->records['caa'],$existingOnly->records['srv'],$existingOnly->records['same-owner-txt']),'editing an original DNS record preserves unrelated types and same-name TXT');
$beforeProxy=$existingOnly->records['ipv6'];$proxy=$actions->execute($existingOnlyZone,['action'=>'set_mode','dns_mode'=>'proxied','records'=>[$rawRef('ipv6')],'preview'=>false],'test')['items'][0];$finishAction($proxy['operation_id']);
check($existingOnly->records['ipv6']['content']===$beforeProxy['content']&&$existingOnly->records['ipv6']['proxied']&&$existingOnly->records['ipv6']['ttl']===1,'opening the cloud preserves IPv6 content and applies automatic TTL');
$close=$actions->execute($existingOnlyZone,['action'=>'set_mode','dns_mode'=>'dns_only','records'=>[$rawRef('ipv6')],'preview'=>false],'test')['items'][0];$finishAction($close['operation_id']);
check(!$existingOnly->records['ipv6']['proxied']&&$existingOnly->records['ipv6']['content']===$beforeProxy['content'],'closing the cloud preserves original address content');
$invalid=$actions->execute($existingOnlyZone,['action'=>'set_mode','dns_mode'=>'proxied','records'=>[$rawRef('txt-0')],'preview'=>true],'test');
check($invalid['summary']['failed']===1&&str_contains($invalid['items'][0]['error']['message'],'A / AAAA / CNAME'),'non-address record cloud modes fail per item instead of changing TXT');
$invalidIp=$actions->execute($existingOnlyZone,['action'=>'update','records'=>[[...$rawRef('ipv6'),'changes'=>['content'=>'9.9.9.9']]],'preview'=>true],'test');
check($invalidIp['summary']['failed']===1,'record editing validates address families before submission');
$changedRef=$rawRef('txt-1');$existingOnly->records['txt-1']['content']='external changed';$countBefore=(int)$db->value('SELECT COUNT(*) FROM dns_operations');
$stale=$actions->execute($existingOnlyZone,['action'=>'delete','records'=>[$changedRef],'preview'=>false],'test');
check($stale['summary']['failed']===1&&$stale['items'][0]['error']['status']===409&&(int)$db->value('SELECT COUNT(*) FROM dns_operations')===$countBefore,'external changes before submission reject the stale request without queuing');$existingOnly->records['txt-1']['content']='txt-value-1';
$race=$actions->execute($existingOnlyZone,['action'=>'update','records'=>[[...$rawRef('txt-2'),'changes'=>['content'=>'requested text']]],'preview'=>false],'test')['items'][0];
$raceWrites=$writeCount();$existingOnly->records['txt-2']['content']='changed after submission';$stepActions();
check($operations->detail($race['operation_id'])['status']==='needs_attention'&&$writeCount()===$raceWrites&&$existingOnly->records['txt-2']['content']==='changed after submission','executor rechecks the original baseline and never overwrites a later external edit');
$existingOnly->loseDnsPatchResponse=true;$lostEdit=$actions->execute($existingOnlyZone,['action'=>'update','records'=>[[...$rawRef('txt-3'),'changes'=>['content'=>'confirmed despite lost response']]],'preview'=>false],'test')['items'][0];$lostWrites=$writeCount();$stepActions();$finishAction($lostEdit['operation_id']);
check($writeCount()===$lostWrites+1&&$existingOnly->records['txt-3']['content']==='confirmed despite lost response','lost update responses reconcile from DNS readback without repeating the write');
$existingOnly->loseDnsDeleteResponse=true;$lostDelete=$actions->execute($existingOnlyZone,['action'=>'delete','records'=>[$rawRef('txt-4')],'preview'=>false],'test')['items'][0];$lostDeleteWrites=$writeCount();$stepActions();$finishAction($lostDelete['operation_id']);
check(!isset($existingOnly->records['txt-4'])&&$writeCount()===$lostDeleteWrites+1&&isset($existingOnly->records['txt-5']),'lost delete responses reconcile absence without deleting any other record');
$caa=$actions->execute($existingOnlyZone,['action'=>'update','records'=>[[...$rawRef('caa'),'changes'=>['data'=>['flags'=>0,'tag'=>'issue','value'=>'new.example.test']]]],'preview'=>false],'test')['items'][0];$finishAction($caa['operation_id']);
check($existingOnly->records['caa']['data']['value']==='new.example.test'&&$existingOnly->records['caa']['ttl']===3600,'structured DNS data editing preserves unrelated record properties');
$mixed=$actions->execute($existingOnlyZone,['action'=>'set_mode','dns_mode'=>'proxied','records'=>[['id'=>$existingAdopted['domain_id'],'expected_version'=>1],$rawRef('txt-5'),$rawRef('ipv6')],'preview'=>true],'test');
check($mixed['summary']['ready']===2&&$mixed['summary']['failed']===1,'mixed managed and original records support per-item bulk validation');
$duplicate=$actions->execute($existingOnlyZone,['action'=>'update','records'=>[[...$rawRef('txt-6'),'changes'=>['ttl'=>600]]],'preview'=>false],'test')['items'][0];
check($actions->execute($existingOnlyZone,['action'=>'delete','records'=>[$rawRef('txt-6')],'preview'=>false],'test')['summary']['failed']===1,'a record with queued work cannot receive a second competing mutation');$finishAction($duplicate['operation_id']);

$existingOnly->preserveHostStatus=false;$existingOnly->ready=false;
foreach(['new-a'=>['A','9.9.9.81'],'new-v6'=>['AAAA','2606:4700::1001'],'new-cname'=>['CNAME','backend.external.test']] as $id=>[$type,$content]){$existingOnly->records[$id]=['id'=>$id,'name'=>$id.'.existing-only.test','type'=>$type,'content'=>$content,'ttl'=>300,'proxied'=>false,'comment'=>'original source record'];}
$existingOnly->records['new-a-txt']=['id'=>'new-a-txt','name'=>'new-a.existing-only.test','type'=>'TXT','content'=>'keep this verification','ttl'=>3600];
$actionCache->sync($existingOnlyZone,$cloud,'test');$preferredOptions=['action'=>'set_mode','dns_mode'=>'preferred','profile_id'=>$existingAdopted['profile_id'],'entry_id'=>$existingAdopted['entry_id'],'preview'=>true];
$ordinaryOriginal=$existingOnly->records['new-a'];$preferredWrites=$writeCount();$ordinaryPreview=$actions->execute($existingOnlyZone,[...$preferredOptions,'records'=>[$rawRef('new-a')]],'test');
check($ordinaryPreview['summary']['ready']===1&&$writeCount()===$preferredWrites&&$existingOnly->records['new-a']===$ordinaryOriginal,'preferred preview for original A with same-name TXT preserves all DNS and SaaS state');
$ordinaryCommit=$actions->execute($existingOnlyZone,[...$preferredOptions,'preview'=>false,'records'=>[$ordinaryPreview['items'][0]]],'test')['items'][0];
$stepActions(6);
check($existingOnly->records['new-a']===$ordinaryOriginal&&$existingOnly->records['new-a-txt']['content']==='keep this verification','preferred activation leaves original business DNS in place while Cloudflare validation is pending');
check((int)$db->value("SELECT COUNT(*) FROM verification_records v JOIN domains d ON d.id=v.domain_id WHERE d.hostname='new-a.existing-only.test' AND v.status='present'")>0,'preferred activation uses TXT validation without fabricating a standard CNAME');
$existingOnly->ready=true;$ordinaryDone=$finishAction($ordinaryCommit['operation_id']);$ordinaryDomain=$service->detail((int)$ordinaryDone['domain_id']);
check($ordinaryDomain['status']==='active'&&$ordinaryDomain['dns_mode']==='preferred'&&$ordinaryDomain['applied_config']['profile']['cname_target']==='','original A converts to working preferred mode even when the observed SaaS profile has no standard CNAME');
$newAddress=array_values(array_filter($existingOnly->records,fn($r)=>$r['name']==='new-a.existing-only.test'&&$r['type']==='CNAME'));
check(count($newAddress)===1&&$newAddress[0]['content']==='dns.existing-only.test'&&isset($existingOnly->records['new-a-txt']),'preferred conversion replaces routing with the selected entry and preserves unrelated same-name TXT');
foreach(['new-v6','new-cname'] as $originalId){$p=$actions->execute($existingOnlyZone,[...$preferredOptions,'records'=>[$rawRef($originalId)]],'test');check($p['summary']['ready']===1,'IPv6 and CNAME originals expose a valid preferred plan');$q=$actions->execute($existingOnlyZone,[...$preferredOptions,'preview'=>false,'records'=>[$p['items'][0]]],'test')['items'][0];$done=$finishAction($q['operation_id']);check($service->detail((int)$done['domain_id'])['dns_mode']==='preferred','IPv6 and CNAME originals complete preferred activation');}
$existingOnly->records['multi-new-a']=['id'=>'multi-new-a','name'=>'multi-new.existing-only.test','type'=>'A','content'=>'9.9.9.82','ttl'=>300,'proxied'=>false];$existingOnly->records['multi-new-b']=['id'=>'multi-new-b','name'=>'multi-new.existing-only.test','type'=>'AAAA','content'=>'2606:4700::1111','ttl'=>600,'proxied'=>false];$actionCache->sync($existingOnlyZone,$cloud,'test');
$multiPlan=$actions->execute($existingOnlyZone,[...$preferredOptions,'records'=>[$rawRef('multi-new-a')]],'test');
check($multiPlan['summary']['failed']===1,'multi-address preferred conversion refuses to guess a source or silently replace sibling addresses');
$multiOptions=[...$preferredOptions,'include_same_name'=>true,'source_ip'=>'9.9.9.88'];$multiPreview=$actions->execute($existingOnlyZone,[...$multiOptions,'records'=>[$rawRef('multi-new-a'),$rawRef('multi-new-b')]],'test');
check($multiPreview['summary']['ready']===1&&$multiPreview['summary']['unchanged']===1&&count($multiPreview['items'][0]['before'])===2,'explicit multi-address scope is shown in preview and produces one hostname-level preferred task');
$multiQueue=$actions->execute($existingOnlyZone,[...$multiOptions,'preview'=>false,'records'=>[$multiPreview['items'][0]]],'test')['items'][0];$multiDone=$finishAction($multiQueue['operation_id']);
check($service->detail((int)$multiDone['domain_id'])['applied_config']['origin']['source_ip']==='9.9.9.88','multi-address preferred conversion uses the explicitly chosen source');
$service->action((int)$multiDone['domain_id'],'rollback','test');$stepActions(8);$rolled=$service->detail((int)$multiDone['domain_id']);
check($rolled['dns_mode']==='dns_only'&&count(array_filter($existingOnly->records,fn($r)=>$r['name']==='multi-new.existing-only.test'))===2,'rollback of a preferred conversion restores the complete original multi-address set');
check(!array_filter($existingOnly->records,fn($r)=>$r['type']==='CNAME'&&!empty($r['proxied'])&&$r['content']==='origin.existing-only.test'),'original DNS preferred switching never creates an unsolicited standard orange CNAME');
foreach(['plain-ip'=>['A','9.9.9.90'],'plain-alias'=>['CNAME','source.external.test']] as $id=>[$type,$content]){$existingOnly->records[$id]=['id'=>$id,'name'=>$id.'.existing-only.test','type'=>$type,'content'=>$content,'ttl'=>300,'proxied'=>false];}
$actionCache->sync($existingOnlyZone,$cloud,'test');
foreach(['plain-ip','plain-alias'] as $id){foreach(['proxied','dns_only'] as $mode){$q=$actions->execute($existingOnlyZone,['action'=>'set_mode','dns_mode'=>$mode,'records'=>[$rawRef($id)],'preview'=>false],'test')['items'][0];$finishAction($q['operation_id']);}check($existingOnly->records[$id]['content']===($id==='plain-ip'?'9.9.9.90':'source.external.test')&&!$existingOnly->records[$id]['proxied'],'original A and CNAME retain content through both cloud toggle directions');}
$existingOnly->records['queued-preferred']=['id'=>'queued-preferred','name'=>'queued-preferred.existing-only.test','type'=>'A','content'=>'9.9.9.91','ttl'=>300,'proxied'=>false];$actionCache->sync($existingOnlyZone,$cloud,'test');
$queuedPreview=$actions->execute($existingOnlyZone,[...$preferredOptions,'records'=>[$rawRef('queued-preferred')]],'test');$queuedConversion=$actions->execute($existingOnlyZone,[...$preferredOptions,'preview'=>false,'records'=>[$queuedPreview['items'][0]]],'test')['items'][0];$preparedWrites=$writeCount();
$stepActions();$existingOnly->records['queued-preferred']['ttl']=1200;$stepActions(5);
check($operations->detail($queuedConversion['operation_id'])['status']==='needs_attention'&&$writeCount()===$preparedWrites&&$existingOnly->records['queued-preferred']['ttl']===1200,'queued preferred conversion rechecks its approved DNS before creating any cloud resources');

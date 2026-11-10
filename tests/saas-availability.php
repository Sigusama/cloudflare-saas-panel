<?php
// SPDX-License-Identifier: AGPL-3.0-only
use Panel\Services\{SaasInventory,SaasDiscovery,ZoneRecords};
use Panel\Core\AppException;

$plainDnsClient=new FakeCloudflare('plain-dns-zone','plain-dns.test');
$plainDnsClient->records['mail-only']=['id'=>'mail-only','name'=>'mail.plain-dns.test','type'=>'CNAME','content'=>'mail.external.test','proxied'=>false,'ttl'=>300];
$plainDnsAccount=$configs->save('accounts',['name'=>'DNS without SaaS','token'=>str_repeat('n',40)],'test');$clients[$plainDnsAccount]=$plainDnsClient;$configs->sync($plainDnsAccount);
$plainDnsClient->readFailures['/zones/plain-dns-zone/custom_hostnames/fallback_origin']=['times'=>1,'status'=>404];
$availabilityReader=new SaasInventory($db,$cloud);$available=$completeInventory($availabilityReader,$availabilityReader->begin(['account_ids'=>[$plainDnsAccount]],'test'));
$plainStatus=array_values(array_filter($available['zones'],fn($z)=>$z['zone_name']==='plain-dns.test'))[0];
check($plainStatus['fallback_status']==='not_configured'&&!$plainStatus['errors']&&$plainStatus['dns_count']===1&&$plainStatus['hostname_count']===0,'a DNS-only Zone with no SaaS fallback is normal availability, not a failed inventory');
$plainDnsClient->readFailures['/zones/plain-dns-zone/custom_hostnames/fallback_origin']=['times'=>1,'status'=>404];
try{(new SaasDiscovery($db,$cloud))->read($plainDnsAccount,'plain-dns-zone');throw new RuntimeException('Expected no SaaS');}catch(AppException $e){check($e->errorCode==='saas_not_configured'&&$e->status===422,'manual discovery explains an unconfigured SaaS fallback without raw CF 1551');}
$plainDnsClient->readFailures['/zones/plain-dns-zone/custom_hostnames/fallback_origin']=['times'=>1,'status'=>403];
$deniedAvailability=$completeInventory($availabilityReader,$availabilityReader->begin(['account_ids'=>[$plainDnsAccount]],'test'));$deniedZone=array_values(array_filter($deniedAvailability['zones'],fn($z)=>$z['zone_name']==='plain-dns.test'))[0];
check($deniedZone['fallback_status']==='failed'&&isset($deniedZone['errors']['fallback']),'real SaaS permission errors remain visible and are not treated as unconfigured');
$missingTransport=new CredentialTransport(str_repeat('k',40));$missingTransport->reply=['success'=>false,'errors'=>[['code'=>1551,'message'=>'Resource not found.']]];
try{$missingTransport->request('GET','/zones/known/custom_hostnames/fallback_origin');throw new RuntimeException('Expected missing fallback');}catch(AppException $e){check($e->status===404&&$e->errorCode==='cloudflare_not_found','CF error 1551 has structured not-found semantics');}
$typeResults=(new ZoneRecords($db))->records($existingOnlyZone,1,100,'CNAME',true);
check((bool)array_filter($typeResults['items'],fn($r)=>$r['managed']&&$r['hostname']==='app.existing-only.test'),'CNAME search includes managed business CNAME records as well as raw records');

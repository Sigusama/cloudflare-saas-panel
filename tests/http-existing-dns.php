<?php
// SPDX-License-Identifier: AGPL-3.0-only
use Panel\Core\Validator;

$rawSuffix=bin2hex(random_bytes(4));$rawZoneName='original-'.$rawSuffix.'.test';
$rawAccount=requestHttp('/api/admin/accounts','POST',['name'=>'Existing DNS '.$rawSuffix,'token'=>str_repeat('r',40)],$headers)['json']['data']['id'];
$rawZone=$database->insert('INSERT INTO zones(account_id,cf_id,name,status) VALUES(?,?,?,?)',[$rawAccount,'raw-zone-'.$rawSuffix,$rawZoneName,'active']);
$rawHost='app.'.$rawZoneName;$rawOrigin='origin.'.$rawZoneName;$rawAlias='dns.'.$rawZoneName;
$rawFixtureRecords=[
 ['id'=>'raw-origin','name'=>$rawOrigin,'type'=>'A','content'=>'9.9.9.71','proxied'=>true,'ttl'=>1],
 ['id'=>'raw-alias','name'=>$rawAlias,'type'=>'CNAME','content'=>'preferred.external.test','proxied'=>false,'ttl'=>600],
 ['id'=>'raw-business','name'=>$rawHost,'type'=>'CNAME','content'=>$rawAlias,'proxied'=>false,'ttl'=>300,'comment'=>'operator record'],
 ['id'=>'raw-txt','name'=>$rawHost,'type'=>'TXT','content'=>'keep unrelated TXT','ttl'=>3600],
 ['id'=>'raw-mx','name'=>$rawZoneName,'type'=>'MX','content'=>'mail.external.test','priority'=>20,'ttl'=>3600],
 ['id'=>'raw-caa','name'=>$rawZoneName,'type'=>'CAA','content'=>'0 issue example.test','data'=>['flags'=>0,'tag'=>'issue','value'=>'example.test'],'ttl'=>3600]
];
for($n=0;$n<103;$n++){$rawFixtureRecords[]=['id'=>'raw-extra-'.$n,'name'=>'text-'.$n.'.'.$rawZoneName,'type'=>'TXT','content'=>'page-two-'.$n,'ttl'=>3600];}
$rawFixture=$inventoryFixture;
$rawFixture[(string)$rawZone]=['fallback'=>['origin'=>$rawOrigin,'status'=>'active'],'hosts'=>[['id'=>'raw-existing-host','hostname'=>$rawHost,'status'=>'active','ssl'=>['status'=>'active'],'custom_origin_server'=>$rawOrigin,'custom_origin_sni'=>'']],'dns'=>$rawFixtureRecords];
$database->run("UPDATE settings SET value=? WHERE name='demo_inventory_json'",[Validator::json($rawFixture)]);
$rawSyncPath='/api/admin/zones/'.$rawZone.'/dns-sync';$rawListPath='/api/v1/zones/'.$rawZone.'/records?include_existing=1';
expectHttp(requestHttp($rawSyncPath,'POST',[])['status']===403,'DNS snapshot synchronization requires CSRF');
expectHttp(requestHttp($rawSyncPath,'POST',[],$writeAuth,false)['status']===403,'public API keys cannot initiate administrator DNS synchronization');
expectHttp(requestHttp($rawListPath,'GET',null,[],false)['status']===401,'anonymous callers cannot read original DNS records');
$rawSync=requestHttp($rawSyncPath,'POST',[],$headers);
expectHttp($rawSync['status']===200&&$rawSync['json']['data']['count']===count($rawFixtureRecords),'HTTP synchronization loads all original DNS types across pages');
$rawFirst=requestHttp($rawListPath.'&per_page=100','GET',null,$writeAuth,false)['json']['data'];$rawSecond=requestHttp($rawListPath.'&per_page=100&page=2','GET',null,$writeAuth,false)['json']['data'];
expectHttp($rawFirst['total']===count($rawFixtureRecords)&&count($rawFirst['items'])===100&&count($rawSecond['items'])===9,'HTTP full DNS pagination does not omit page-two TXT records');
$rawAll=array_column([...$rawFirst['items'],...$rawSecond['items']],null,'id');
expectHttp($rawAll['cf:raw-mx']['record']['priority']===20&&$rawAll['cf:raw-caa']['record']['data']['tag']==='issue'&&$rawAll['cf:raw-business']['record']['ttl']===300,'HTTP preserves MX and CAA attributes plus original business TTL');
expectHttp(requestHttp($rawListPath.'&search=page-two-102','GET',null,$writeAuth,false)['json']['data']['total']===1,'HTTP DNS search includes original record contents');
$rawFixture[(string)$rawZone]['dns'][]=$rawFixtureRecords[0];$database->run("UPDATE settings SET value=? WHERE name='demo_inventory_json'",[Validator::json($rawFixture)]);
expectHttp(requestHttp($rawSyncPath,'POST',[],$headers)['status']===503&&requestHttp($rawListPath)['json']['data']['total']===count($rawFixtureRecords),'HTTP malformed duplicate snapshot preserves the previous complete DNS list');
$rawFixture[(string)$rawZone]['dns']=$rawFixtureRecords;$database->run("UPDATE settings SET value=? WHERE name='demo_inventory_json'",[Validator::json($rawFixture)]);
$rawScan=requestHttp('/api/admin/saas-inventory','POST',['account_ids'=>[(int)$rawAccount]],$headers)['json']['data'];
for($n=0;$n<100&&$rawScan['run']['state']!=='completed';$n++){$rawScan=requestHttp('/api/admin/saas-inventory/'.$rawScan['run']['id'].'/step','POST',[],$headers)['json']['data'];}
$rawRun=$rawScan['run']['id'];$rawItem=requestHttp('/api/admin/saas-inventory/'.$rawRun.'/items?search='.$rawHost)['json']['data']['items'][0];
expectHttp($rawItem['confidence']==='pending'&&!$rawItem['blockers']&&!$rawItem['profile']['cname_candidates'],'HTTP allows existing preferred SaaS without inventing a standard orange CNAME');
$rawAdoptionPath='/api/admin/saas-inventory/'.$rawRun.'/adopt';$rawAdoption=['key'=>$rawItem['key'],'confirm_preferred'=>true,'preview'=>true];
expectHttp(requestHttp($rawAdoptionPath,'POST',$rawAdoption,$headers)['status']===200,'HTTP existing-only adoption preview accepts the observed preferred route');
$rawAdoption['preview']=false;$rawAdopt=requestHttp($rawAdoptionPath,'POST',$rawAdoption,$headers);
expectHttp($rawAdopt['status']===200&&!$rawAdopt['json']['data']['reused'],'HTTP existing-only adoption commits local management successfully');
$rawDomainId=$rawAdopt['json']['data']['domain_id'];$rawDomain=requestHttp('/api/v1/domains/'.$rawDomainId,'GET',null,$writeAuth,false)['json']['data'];
expectHttp($rawDomain['applied_config']['profile']['cname_target']===''&&(int)$rawDomain['adopted']===1&&$rawDomain['jobs'][0]['kind']==='inspect'&&!$rawDomain['hostname_created'],'HTTP stores no fabricated bootstrap and schedules only read-only inspection');
$rawManaged=requestHttp($rawListPath.'&search='.$rawHost,'GET',null,$writeAuth,false)['json']['data']['items'];
expectHttp(count($rawManaged)===2&&count(array_filter($rawManaged,fn($r)=>$r['managed']))===1&&count(array_filter($rawManaged,fn($r)=>!$r['managed']&&$r['record_type']==='TXT'))===1,'HTTP adopted record replaces its raw routing row while unrelated TXT at the same name remains visible');
$rawExport=requestHttp('/api/admin/export?zone_id='.$rawZone)['json']['data'];
expectHttp($rawExport['format_version']===5,'HTTP existing-only SaaS export identifies portable format five');
expectHttp(json_decode($database->value("SELECT value FROM settings WHERE name='demo_inventory_json'"),true)[(string)$rawZone]['dns']===$rawFixtureRecords,'HTTP loading and adoption preserve the entire original DNS fixture');
requestHttp('/api/v1/domains/'.$rawDomainId,'DELETE',['mode'=>'forget'],$writeAuth,false);
requestHttp('/api/admin/accounts/'.$rawAccount,'PATCH',['active'=>false],$headers);

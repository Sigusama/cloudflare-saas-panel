<?php
// SPDX-License-Identifier: AGPL-3.0-only
use Panel\Core\Validator;
$inventoryProfile=$database->one('SELECT * FROM profiles WHERE id=?',[$profileId]);
$inventorySuffix=bin2hex(random_bytes(4));$inventoryHost='discovered-'.$inventorySuffix.'.customer.test';$inventoryAlias='entry-'.$inventorySuffix.'.customer.test';
$inventoryFixture=[(string)$zoneId=>['fallback'=>['origin'=>$inventoryProfile['fallback_origin'],'status'=>'active'],'hosts'=>[['id'=>'external-http-'.$inventorySuffix,'hostname'=>$inventoryHost,'status'=>'active','ssl'=>['status'=>'active'],'custom_origin_server'=>'','custom_origin_sni'=>'']],'dns'=>[
    ['id'=>'standard-http-'.$inventorySuffix,'name'=>$inventoryProfile['cname_target'],'type'=>'CNAME','content'=>$inventoryProfile['fallback_origin'],'proxied'=>true,'ttl'=>1],
    ['id'=>'source-http-'.$inventorySuffix,'name'=>$inventoryProfile['fallback_origin'],'type'=>'A','content'=>'9.9.9.200','proxied'=>true,'ttl'=>1],
    ['id'=>'business-http-'.$inventorySuffix,'name'=>$inventoryHost,'type'=>'CNAME','content'=>$inventoryAlias,'proxied'=>false,'ttl'=>300,'comment'=>'original operator record'],
    ['id'=>'entry-http-a-'.$inventorySuffix,'name'=>$inventoryAlias,'type'=>'A','content'=>'1.1.1.1','proxied'=>false,'ttl'=>300],
    ['id'=>'entry-http-v6-'.$inventorySuffix,'name'=>$inventoryAlias,'type'=>'AAAA','content'=>'2606:4700:4700::1111','proxied'=>false,'ttl'=>300]
]]];
$database->run("INSERT INTO settings(name,value) VALUES('demo_inventory_json',?) ON DUPLICATE KEY UPDATE value=VALUES(value)",[Validator::json($inventoryFixture)]);
expectHttp(requestHttp('/api/admin/saas-inventory','GET',null,[],false)['status']===401,'anonymous callers cannot read existing SaaS inventory');
expectHttp(requestHttp('/api/admin/saas-inventory','GET',null,$writeAuth,false)['status']===403,'public API keys cannot read administrator cloud inventory');
expectHttp(requestHttp('/api/admin/saas-inventory','POST',[],$writeAuth,false)['status']===403,'public API domain write permission cannot start administrator scans');
expectHttp(requestHttp('/api/admin/saas-inventory','POST',[])['status']===403,'starting an inventory scan requires CSRF');
expectHttp(requestHttp('/api/admin/saas-inventory','POST',['account_ids'=>['invalid']],$headers)['status']===422,'HTTP inventory scope validates active account identifiers');
$scanKey='http-inventory-'.bin2hex(random_bytes(5));$scope=['account_ids'=>[(int)$accountId]];
$scan=requestHttp('/api/admin/saas-inventory','POST',$scope,[...$headers,'Idempotency-Key: '.$scanKey]);
expectHttp($scan['status']===201&&$scan['json']['data']['run']['source']==='demo','HTTP scan creates a resumable demo plan without invoking Cloudflare');
expectHttp(requestHttp('/api/admin/saas-inventory','POST',$scope,[...$headers,'Idempotency-Key: '.$scanKey])['json']['data']===$scan['json']['data'],'HTTP scan start supports idempotency replay');
$scanId=$scan['json']['data']['run']['id'];$scanState=$scan['json']['data'];
for($inventoryStep=0;$inventoryStep<100&&$scanState['run']['state']!=='completed';$inventoryStep++){$step=requestHttp('/api/admin/saas-inventory/'.$scanId.'/step','POST',[],$headers);if($step['status']!==200){throw new RuntimeException('HTTP inventory step failed: '.$step['text']);}$scanState=$step['json']['data'];}
expectHttp($scanState['run']['state']==='completed'&&$scanState['run']['total']===1,'HTTP inventory incrementally reads and links existing SaaS and DNS');
expectHttp(requestHttp('/api/admin/saas-inventory')['json']['data']['run']['id']===$scanId,'latest scan is available after reopening the panel');
$inventoryRows=requestHttp('/api/admin/saas-inventory/'.$scanId.'/items?search=discovered-'.$inventorySuffix)['json']['data'];$inventoryItem=$inventoryRows['items'][0];
$inventoryValidRoutes=array_values(array_filter($inventoryItem['routes'],fn($route)=>!$route['blockers']));
expectHttp($inventoryRows['total']===1&&$inventoryItem['confidence']==='pending'&&count($inventoryValidRoutes)===1&&count($inventoryValidRoutes[0]['entry']['targets'])===2,'HTTP discovery displays a two-address entry with pending preferred purpose');
expectHttp($inventoryItem['origin']['targets']===['9.9.9.200']&&$inventoryItem['profile']['cname_target']===$inventoryProfile['cname_target'],'HTTP discovery automatically fills the actual source and unique standard CNAME');
$adoptionInput=['key'=>$inventoryItem['key'],'confirm_preferred'=>true,'preview'=>true];$adoptionPath='/api/admin/saas-inventory/'.$scanId.'/adopt';
expectHttp(requestHttp($adoptionPath,'POST',[...$adoptionInput,'confirm_preferred'=>false],$headers)['status']===422,'HTTP uncertain preferred parsing needs explicit confirmation');
expectHttp(requestHttp($adoptionPath,'POST',$adoptionInput,$writeAuth,false)['status']===403,'public API keys cannot adopt cloud inventory');
$inventoryJobCount=(int)$database->value('SELECT COUNT(*) FROM jobs');$inventoryEntryCount=(int)$database->value('SELECT COUNT(*) FROM entries');
$adoptPreview=requestHttp($adoptionPath,'POST',$adoptionInput,$headers);
expectHttp($adoptPreview['status']===200&&$adoptPreview['json']['data']['preview']&&(int)$database->value('SELECT COUNT(*) FROM jobs')===$inventoryJobCount&&(int)$database->value('SELECT COUNT(*) FROM entries')===$inventoryEntryCount,'HTTP adoption preview rolls back configurations and inspection jobs');
$adoptionKey='http-adopt-'.bin2hex(random_bytes(5));$adoptionInput['preview']=false;
$adoptedHttp=requestHttp($adoptionPath,'POST',$adoptionInput,[...$headers,'Idempotency-Key: '.$adoptionKey]);
expectHttp($adoptedHttp['status']===200&&!$adoptedHttp['json']['data']['reused'],'HTTP adoption preserves current DNS while creating a local management record');
expectHttp(requestHttp($adoptionPath,'POST',$adoptionInput,[...$headers,'Idempotency-Key: '.$adoptionKey])['json']['data']===$adoptedHttp['json']['data'],'HTTP adoption replay cannot duplicate local domains or jobs');
$adoptedHttpId=$adoptedHttp['json']['data']['domain_id'];$adoptedHttpDetail=requestHttp('/api/v1/domains/'.$adoptedHttpId,'GET',null,$writeAuth,false)['json']['data'];
expectHttp((int)$adoptedHttpDetail['adopted']===1&&!(bool)$adoptedHttpDetail['hostname_created']&&$adoptedHttpDetail['jobs'][0]['kind']==='inspect','public domain detail distinguishes pre-existing external resources and read-only inspection');
expectHttp($adoptedHttpDetail['status']==='queued'&&$adoptedHttpDetail['applied_config']['origin']['source_ip']==='9.9.9.200'&&$adoptedHttpDetail['applied_config']['entry']['hostname']===$inventoryAlias,'adoption fills source and entry without claiming that pending access checks passed');
expectHttp(requestHttp('/api/v1/domains/'.$adoptedHttpId.'/rollback','POST',[],$writeAuth,false)['status']===409,'HTTP unmodified adopted records do not offer a fictitious original-onboarding rollback');
$inventoryFixture[(string)$zoneId]['dns'][1]['content']='4.2.2.2';$database->run("UPDATE settings SET value=? WHERE name='demo_inventory_json'",[Validator::json($inventoryFixture)]);
expectHttp(requestHttp($adoptionPath,'POST',$adoptionInput,$headers)['status']===409,'HTTP adoption revalidates the current source and refuses changed scan data');
$inventoryFixture[(string)$zoneId]['dns'][1]['content']='9.9.9.200';$database->run("UPDATE settings SET value=? WHERE name='demo_inventory_json'",[Validator::json($inventoryFixture)]);
expectHttp(requestHttp($adoptionPath,'POST',$adoptionInput,$headers)['json']['data']['reused'],'HTTP repeated adoption can reuse unchanged already managed records');
$inventoryZoneList=requestHttp('/api/v1/zones/'.$zoneId.'/records?search=discovered-'.$inventorySuffix,'GET',null,$writeAuth,false)['json']['data']['items'];
expectHttp(count($inventoryZoneList)===1&&$inventoryZoneList[0]['dns_records'][0]['content']===$inventoryAlias&&$inventoryZoneList[0]['source']==='9.9.9.200','adopted records appear in normal DNS management with separate preferred entry and origin');
requestHttp('/api/v1/domains/'.$adoptedHttpId,'DELETE',['mode'=>'forget'],$writeAuth,false);
expectHttp(requestHttp('/api/admin/accounts/'.$accountId.'/sync','POST',[],$headers)['status']===200,'demo account synchronization remains local before automatic SaaS reading');

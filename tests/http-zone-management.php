<?php
// SPDX-License-Identifier: AGPL-3.0-only
$zoneCreationInput=['account_id'=>$accountId,'cf_account_id'=>str_repeat('a',32),'name'=>'zone-http.example.test'];
expectHttp(requestHttp('/api/admin/zones','POST',$zoneCreationInput)['status']===403,'zone creation requires administrator CSRF');
foreach(['/api/admin/accounts/'.$accountId.'/cloudflare-accounts','/api/admin/zones/'.$zoneId.'/details'] as $path){expectHttp(requestHttp($path,'GET',null,$writeAuth,false)['status']===403,'API keys cannot access administrative zone metadata');}
expectHttp(requestHttp('/api/admin/zones','POST',$zoneCreationInput,$writeAuth,false)['status']===403,'even a write-scoped API key cannot create Cloudflare zones');
$beforeZoneCount=(int)$database->value('SELECT COUNT(*) FROM zones');
$targets=requestHttp('/api/admin/accounts/'.$accountId.'/cloudflare-accounts');
expectHttp($targets['status']===200&&$targets['json']['data']['demo']&&$targets['json']['data']['items']===[],'demo target account discovery does not contact Cloudflare');
foreach([['account_id'=>0],['account_id'=>true],['account_id'=>1.5],['name'=>'https://example.test'],['cf_account_id'=>'123']] as $invalid){expectHttp(requestHttp('/api/admin/zones','POST',array_replace($zoneCreationInput,$invalid),$headers)['status']===422,'administrative zone input is validated before cloud access');}
$demoCreate=requestHttp('/api/admin/zones','POST',$zoneCreationInput,[...$headers,'Accept-Language: en']);
expectHttp($demoCreate['status']===409&&$demoCreate['json']['error']['code']==='demo_read_only'&&!preg_match('/\p{Han}/u',$demoCreate['json']['error']['message']),'demo zone creation is rejected with localized error and stable code');
expectHttp(requestHttp('/api/admin/zones/'.$zoneId.'/details')['status']===409&&(int)$database->value('SELECT COUNT(*) FROM zones')===$beforeZoneCount,'rejected demo actions retain all local zones and avoid real metadata reads');
$zoneDialog=requestHttp('/','GET',null,['Accept-Language: en']);
expectHttp(str_contains($zoneDialog['text'],'id="zone-create-form"')&&str_contains($zoneDialog['text'],'Add a Cloudflare zone')&&str_contains($zoneDialog['text'],'id="zone-info-dialog"'),'administrator UI includes translated zone creation and NS dialogs');

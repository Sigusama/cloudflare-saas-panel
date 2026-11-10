<?php
// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

$globalHttpKey='cfk_'.str_repeat('q',48);$globalHttpEmail='global-http@example.test';
$globalHttpInput=['name'=>'HTTP Global Key fixture','auth_type'=>'global_key','email'=>$globalHttpEmail,'global_key'=>$globalHttpKey];
expectHttp(requestHttp('/api/admin/accounts','POST',$globalHttpInput)['status']===403,'Global Key creation requires CSRF');
$globalHttpCreate=requestHttp('/api/admin/accounts','POST',$globalHttpInput,$headers);
expectHttp($globalHttpCreate['status']===201,'admin can add Global Key account over HTTP');
$globalHttpId=$globalHttpCreate['json']['data']['id'];$globalHttpPath='/api/admin/accounts/'.$globalHttpId;
expectHttp(requestHttp($globalHttpPath.'/auth','GET',null,$writeAuth,false)['status']===403,'public API key cannot read account authentication metadata');
$globalHttpMeta=requestHttp($globalHttpPath.'/auth');
expectHttp($globalHttpMeta['status']===200&&$globalHttpMeta['json']['data']===['auth_type'=>'global_key','email'=>$globalHttpEmail]&&!str_contains($globalHttpMeta['text'],$globalHttpKey),'session metadata returns email but never the key');
$globalHttpConfig=requestHttp('/api/v1/config','GET',null,$writeAuth,false);
expectHttp(!str_contains($globalHttpConfig['text'],$globalHttpKey)&&!str_contains($globalHttpConfig['text'],$globalHttpEmail),'public config hides Global Key and account email');
expectHttp(requestHttp($globalHttpPath,'PATCH',['auth_type'=>'global_key','email'=>$globalHttpEmail,'global_key'=>''],$headers)['status']===200,'blank unchanged Global Key survives HTTP editing');
expectHttp(requestHttp($globalHttpPath,'PATCH',['auth_type'=>'api_token'],$headers)['status']===422,'HTTP mode switch requires replacement credential');
expectHttp(requestHttp($globalHttpPath,'PATCH',['email'=>'bad-email'],$headers)['status']===422,'HTTP account email is validated');
expectHttp(requestHttp($globalHttpPath,'PATCH',['auth_type'=>'api_token','token'=>str_repeat('t',40)],$headers)['status']===200,'HTTP can explicitly replace Global Key with Token');
expectHttp(requestHttp($globalHttpPath.'/auth')['json']['data']===['auth_type'=>'api_token','email'=>null],'HTTP credential switch removes the account email');
expectHttp(requestHttp($globalHttpPath,'PATCH',$globalHttpInput,$headers)['status']===200,'HTTP can switch back with complete Global Key credentials');
$globalHttpAudit=requestHttp('/api/v1/logs?event=config.&per_page=100','GET',null,$writeAuth,false);
$globalHttpExport=requestHttp('/api/admin/export');
expectHttp(!str_contains($globalHttpAudit['text'].$globalHttpExport['text'],$globalHttpKey)&&!str_contains($globalHttpAudit['text'].$globalHttpExport['text'],$globalHttpEmail),'HTTP logs and export omit Global Key credentials');
expectHttp(requestHttp($globalHttpPath,'DELETE',[],$headers)['status']===200&&requestHttp($globalHttpPath.'/auth')['status']===404,'HTTP deletion hides credential metadata');
expectHttp($isolationDb->value('SELECT token_encrypted FROM accounts WHERE id=?',[$globalHttpId])==='','HTTP deletion erases encrypted Global Key and email');

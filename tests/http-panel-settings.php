<?php
// SPDX-License-Identifier: AGPL-3.0-only
use Panel\Core\{Config,Validator};
$panelBefore=Config::read();$panelRead=requestHttp('/api/admin/panel-settings');
$panelOriginalUrl=$panelBefore['app_url'];$panelRestoredUrl=Validator::panelUrl($panelOriginalUrl);
expectHttp($panelRead['status']===200&&$panelRead['json']['data']===['app_url'=>$panelOriginalUrl],'administrator can read only the panel URL');
expectHttp(requestHttp('/api/admin/panel-settings','GET',null,$writeAuth,false)['status']===403,'public API keys cannot read panel administration settings');
$panelBody=['app_url'=>'http://panel.example.test:8080/','expected_app_url'=>$panelOriginalUrl];
expectHttp(requestHttp('/api/admin/panel-settings','PATCH',$panelBody,[],true)['status']===403,'panel URL change requires CSRF');
expectHttp(requestHttp('/api/admin/panel-settings','PATCH',$panelBody,$writeAuth,false)['status']===403,'public API keys cannot change the panel URL');
expectHttp(requestHttp('/api/admin/panel-settings','PATCH',[...$panelBody,'app_url'=>'javascript:alert(1)'],$headers)['status']===422,'panel URL endpoint rejects executable schemes');
$panelCurrent=$base;
try{
    $panelSaved=requestHttp('/api/admin/panel-settings','PATCH',$panelBody,$headers);
    expectHttp($panelSaved['status']===200&&$panelSaved['json']['data']['changed'],'administrator can save a new panel hostname and port');
    $panelCurrent='http://panel.example.test:8080';
    expectHttp(Config::read()===array_replace($panelBefore,['app_url'=>$panelCurrent])&&requestHttp('/api/admin/panel-settings')['json']['data']['app_url']===$panelCurrent,'panel URL readback persists the edit and preserves all private configuration');
    expectHttp(requestHttp('/api/admin/panel-settings','PATCH',[...$panelBody,'app_url'=>'https://stale.example.test'],$headers)['status']===409,'HTTP stale panel settings cannot overwrite the new URL');
    $panelHttps=requestHttp('/api/admin/panel-settings','PATCH',['app_url'=>'https://panel.example.test','expected_app_url'=>$panelCurrent],$headers);
    expectHttp($panelHttps['status']===200,'panel URL can switch to HTTPS without redirecting the current request');
    $panelCurrent='https://panel.example.test';
    expectHttp(requestHttp('/api/admin/panel-settings')['status']===200,'the existing session can continue reviewing the saved panel URL');
    $panelRestore=requestHttp('/api/admin/panel-settings','PATCH',['app_url'=>$panelOriginalUrl,'expected_app_url'=>$panelCurrent],$headers);
    expectHttp($panelRestore['status']===200&&Config::read()===array_replace($panelBefore,['app_url'=>$panelRestoredUrl]),'restoring the isolated test URL keeps the original application key and database');
    $panelCurrent=$panelRestoredUrl;
    expectHttp(requestHttp('/api/admin/panel-settings','PATCH',['app_url'=>$panelRestoredUrl.'/','expected_app_url'=>$panelRestoredUrl],$headers)['json']['data']['changed']===false,'HTTP save reports unchanged when the normalized URL is identical');
    expectHttp((int)$database->value("SELECT COUNT(*) FROM audit_logs WHERE event='panel.url_changed'")>=3,'HTTP panel URL edits record an audit trail');
}finally{
    if(Config::read()['app_url']!==$panelRestoredUrl){requestHttp('/api/admin/panel-settings','PATCH',['app_url'=>$panelRestoredUrl,'expected_app_url'=>Config::read()['app_url']],$headers);}
}

<?php
// SPDX-License-Identifier: AGPL-3.0-only
use Panel\Services\PanelSettings;
use Panel\Core\{Config,Validator};

check(Validator::panelUrl(' HTTPS://Panel.Example.Test:443/ ')==='https://panel.example.test','panel URL normalizes protocol, hostname, default port and root slash');
check(Validator::panelUrl('http://localhost:8080/')==='http://localhost:8080','panel URL allows local development and custom ports');
check(Validator::panelUrl('https://面板.example/')==='https://xn--msvw97h.example','panel URL supports internationalized hostnames');
check(Validator::panelUrl('http://[::1]:8080/')==='http://[::1]:8080','panel URL preserves valid IPv6 authorities');
foreach(['panel.example.test','javascript:alert(1)','https://user:pass@panel.example.test','https://panel.example.test/path','https://panel.example.test/?a=1','https://panel.example.test/#a',"https://panel.example.test\n.evil.test",'http://panel.example.test:0','https://panel.example.test:65536','https://-invalid.test',[],null] as $badPanelUrl){rejects(fn()=>Validator::panelUrl($badPanelUrl),422,'invalid panel URL is rejected');}
$panelSettings=new PanelSettings($db);$panelOriginal=Config::read();$panelOriginalBytes=file_get_contents(Config::storage('config.php'));$panelOriginalLock=hash_file('sha256',Config::storage('installed.lock'));
$panelDnsBefore=$db->all('SELECT * FROM domains ORDER BY id');$panelCredentialsBefore=$db->all('SELECT id,token_encrypted FROM accounts ORDER BY id');
try{
    check($panelSettings->read()===['app_url'=>$panelOriginal['app_url']],'panel settings expose the URL without database or encryption configuration');
    $result=$panelSettings->save(['app_url'=>'https://panel.example.test/','expected_app_url'=>$panelOriginal['app_url']],'test');
    check($result===['app_url'=>'https://panel.example.test','changed'=>true]&&Config::read()===array_replace($panelOriginal,['app_url'=>'https://panel.example.test']),'panel URL persists without changing database credentials or application key');
    check(hash_file('sha256',Config::storage('installed.lock'))===$panelOriginalLock&&$db->all('SELECT * FROM domains ORDER BY id')===$panelDnsBefore&&$db->all('SELECT id,token_encrypted FROM accounts ORDER BY id')===$panelCredentialsBefore,'panel URL edit retains installation lock, domain state and encrypted cloud credentials');
    check($db->value("SELECT message FROM audit_logs WHERE event='panel.url_changed' ORDER BY id DESC LIMIT 1")==='面板访问地址已更新：https://panel.example.test','panel URL changes are audited without private configuration');
    rejects(fn()=>$panelSettings->save(['app_url'=>'https://stale.example.test','expected_app_url'=>$panelOriginal['app_url']],'test'),409,'stale panel URL changes cannot overwrite newer settings');
    rejects(fn()=>$panelSettings->save(['app_url'=>'https://other.example.test','expected_app_url'=>'https://panel.example.test','key'=>'must-not-change'],'test'),422,'panel settings reject unrelated private configuration fields');
    check($panelSettings->save(['app_url'=>'https://panel.example.test/','expected_app_url'=>'https://panel.example.test'],'test')['changed']===false,'saving the same normalized panel URL is a no-op');
    $db->run('CREATE TEMPORARY TABLE audit_logs (invalid_column INT)');
    try{try{$panelSettings->save(['app_url'=>'https://failed.example.test','expected_app_url'=>'https://panel.example.test'],'test');throw new RuntimeException('Audit failure did not occur');}catch(\PDOException){check(Config::read()['app_url']==='https://panel.example.test','audit failure restores the previous panel URL');}}
    finally{$db->run('DROP TEMPORARY TABLE audit_logs');}
}finally{Config::atomicWrite(Config::storage('config.php'),$panelOriginalBytes);if(function_exists('opcache_invalidate')){@opcache_invalidate(Config::storage('config.php'),true);}}
check(Config::read()===$panelOriginal,'isolated panel URL tests restore the original installation configuration');

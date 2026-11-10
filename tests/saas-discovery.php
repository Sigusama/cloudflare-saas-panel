<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Read-only discovery must never guess a SaaS target or mutate Cloudflare.
$discoveryMode=$db->value("SELECT value FROM settings WHERE name='preview_mode'");
$db->run("DELETE FROM settings WHERE name='preview_mode'");
try {
    $discoveryClient=new FakeCloudflare('discovery-zone','discovery.test');
    $discoveryClient->fallbackOrigin=['origin'=>'origin.discovery.test','status'=>'active'];
    $discoveryAccount=$configs->save('accounts',['name'=>'Discovery fixture','token'=>str_repeat('s',40)],'test');
    $clients[$discoveryAccount]=$discoveryClient;$configs->sync($discoveryAccount);
    $discoveryClient->records['target']=['id'=>'target','name'=>'saas.discovery.test','type'=>'CNAME','content'=>'origin.discovery.test','proxied'=>true];
    $discovery=new \Panel\Services\SaasDiscovery($db,$cloud);
    $profilesBefore=(int)$db->value('SELECT COUNT(*) FROM profiles');
    $discovered=$discovery->read($discoveryAccount,'discovery-zone');
    check($discovered['fallback_origin']==='origin.discovery.test'&&$discovered['cname_target']==='saas.discovery.test','CF discovery automatically reads fallback and sole standard CNAME');
    check($discovered['source']==='cloudflare'&&$discovered['fallback_status']==='active','discovery reports provider activation state without claiming local setup');
    check((int)$db->value('SELECT COUNT(*) FROM profiles')===$profilesBefore,'reading CF configuration does not save or replace local presets');
    check(!array_filter($discoveryClient->requests,fn($r)=>$r['method']!=='GET'),'CF discovery performs read-only requests');
    rejects(fn()=>$discovery->read($discoveryAccount,'foreign-zone'),422,'discovery rejects a zone outside the selected account');
    $discoveryClient->records['gray']=['name'=>'gray.discovery.test','type'=>'CNAME','content'=>'origin.discovery.test','proxied'=>false];
    $discoveryClient->records['wildcard']=['name'=>'*.discovery.test','type'=>'CNAME','content'=>'origin.discovery.test','proxied'=>true];
    $discoveryClient->records['foreign']=['name'=>'foreign.test','type'=>'CNAME','content'=>'origin.discovery.test','proxied'=>true];
    $discoveryClient->records['other']=['name'=>'other.discovery.test','type'=>'CNAME','content'=>'another.discovery.test','proxied'=>true];
    check($discovery->read($discoveryAccount,'discovery-zone')['cname_candidates']===['saas.discovery.test'],'discovery excludes gray-cloud, wildcard, foreign and unrelated DNS records');
    $discoveryClient->records['second']=['name'=>'customers.discovery.test','type'=>'CNAME','content'=>'ORIGIN.discovery.test.','proxied'=>true];
    $ambiguous=$discovery->read($discoveryAccount,'discovery-zone');
    check($ambiguous['cname_target']===null&&count($ambiguous['cname_candidates'])===2,'multiple standard CNAMEs require a choice instead of guessing');
    unset($discoveryClient->records['target'],$discoveryClient->records['second']);
    check($discovery->read($discoveryAccount,'discovery-zone')['cname_candidates']===[],'missing standard CNAME is reported without inventing a remote target');
    $discoveryClient->fallbackOrigin=['status'=>'initializing'];
    rejects(fn()=>$discovery->read($discoveryAccount,'discovery-zone'),422,'missing SaaS fallback configuration fails clearly');
    $discoveryClient->fallbackOrigin=['origin'=>'origin.foreign.test','status'=>'active'];
    rejects(fn()=>$discovery->read($discoveryAccount,'discovery-zone'),422,'discovery rejects an incompatible fallback outside the SaaS zone');
    $discoveryClient->failFallbackRead=true;
    rejects(fn()=>$discovery->read($discoveryAccount,'discovery-zone'),422,'CF permission errors are not replaced by fabricated configuration');
    $demoProfile=$configs->save('profiles',['name'=>'Demo discovery','account_id'=>$discoveryAccount,'zone_id'=>'discovery-zone','cname_target'=>'saas.discovery.test','fallback_origin'=>'origin.discovery.test'],'test');
    $db->run("INSERT INTO settings(name,value) VALUES('preview_mode','1')");$requestCount=count($discoveryClient->requests);
    check($discovery->read($discoveryAccount,'discovery-zone')['source']==='demo'&&count($discoveryClient->requests)===$requestCount,'isolated Demo reads local presets without calling Cloudflare');
}finally {
    if($discoveryMode===false){$db->run("DELETE FROM settings WHERE name='preview_mode'");}
    else{$db->run("INSERT INTO settings(name,value) VALUES('preview_mode',?) ON DUPLICATE KEY UPDATE value=VALUES(value)",[$discoveryMode]);}
}

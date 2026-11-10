<?php
// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';

// The CLI suite creates a foreign session after printing assertions. Keep stdout
// buffered so PHP can create that session and the isolation check is meaningful.
ob_start();

use Panel\Services\Installer;
use Panel\Core\{Config,Database,Crypto,Validator};

$base=getenv('TEST_HTTP_URL')?:'http://127.0.0.1:18080';
$testWork=getenv('PANEL_TEST_WORK')?:BASE_PATH.'/work';
if (!is_dir($testWork)) { mkdir($testWork,0700,true); }
$jar=$testWork.'/http-cookies.txt';
$checks=0;
$issuedKeys=[];
function expectHttp(bool $condition,string $message):void { global $checks;if(!$condition){throw new RuntimeException('FAIL '.$message);}$checks++;echo "PASS $message\n"; }
function requestHttp(string $path,string $method='GET',?array $body=null,array $headers=[],bool $session=true):array {
    global $base,$jar;
    $curl=curl_init($base.$path);$responseHeaders=[];
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_TIMEOUT=>10,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_HTTPHEADER=>array_merge(['Accept: application/json'], $body!==null?['Content-Type: application/json']:[], $headers),CURLOPT_HEADERFUNCTION=>static function($handle,string $line)use(&$responseHeaders):int { $responseHeaders[]=$line;return strlen($line); }]);
    if($session){curl_setopt($curl,CURLOPT_COOKIEJAR,$jar);curl_setopt($curl,CURLOPT_COOKIEFILE,$jar);}
    if($body!==null){curl_setopt($curl,CURLOPT_POSTFIELDS,json_encode($body));}
    $text=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);if($text===false){throw new RuntimeException(curl_error($curl));}curl_close($curl);
    return ['status'=>$status,'text'=>$text,'json'=>json_decode($text,true),'headers'=>$responseHeaders];
}
function csrfHttp(string $text):string { if(!preg_match('/name="csrf-token" content="([a-f0-9]+)"/',$text,$m)){throw new RuntimeException('CSRF not found');}return $m[1]; }
try {
    if (!preg_match('#^http://127\.0\.0\.1:\d+$#D',$base)) { throw new RuntimeException('HTTP smoke tests require a local isolated preview'); }
    if (getenv('TEST_HTTP_ISOLATED')!=='1') { throw new RuntimeException('Set TEST_HTTP_ISOLATED=1 only for a dedicated disposable HTTP test installation'); }
    $targetDb=Config::installed()?Config::read()['db']['name']:(getenv('TEST_INSTALL_DB_NAME')?:'cf_panel_preview');
    if (!preg_match('/_(?:test|preview)$/D',$targetDb)) { throw new RuntimeException('HTTP test database must end in _test or _preview'); }
    if (Config::installed()&&rtrim(Config::read()['app_url'],'/')!==rtrim($base,'/')) { throw new RuntimeException('HTTP target must match this isolated storage installation URL'); }
    $health=requestHttp('/health');expectHttp($health['status']===200,'health endpoint responds');
    $loginFile=$testWork.'/preview-login.json';
    if(empty($health['json']['data']['installed'])) {
        $token=(new Installer())->token();$install=requestHttp('/install');$csrf=csrfHttp($install['text']);
        expectHttp(str_contains($install['text'],'安装自动接入面板'),'installer page rendered');
        $password=bin2hex(random_bytes(16));
        $data=['install_token'=>$token,'app_url'=>$base,'db_host'=>getenv('TEST_INSTALL_DB_HOST')?:'127.0.0.1','db_port'=>(int)(getenv('TEST_INSTALL_DB_PORT')?:13316),'db_name'=>getenv('TEST_INSTALL_DB_NAME')?:'cf_panel_preview','db_user'=>getenv('TEST_INSTALL_DB_USER')?:'root','db_password'=>getenv('TEST_INSTALL_DB_PASSWORD')?:'','username'=>'preview-admin','password'=>$password];
        $installed=requestHttp('/install','POST',$data,['X-CSRF-Token: '.$csrf]);
        expectHttp($installed['status']===200&&($installed['json']['data']['redirect']??'')==='/login','HTTP Web installer initializes empty database');
        file_put_contents($loginFile,json_encode(['username'=>'preview-admin','password'=>$password]));
    }
    $credentials=json_decode(file_get_contents($loginFile),true);
    $isolationDb=new Database(Config::read()['db']);
    $isolationDb->operationalLock(fn()=>$isolationDb->run("INSERT INTO settings(name,value) VALUES('preview_mode','1') ON DUPLICATE KEY UPDATE value='1'"),30);
    expectHttp($isolationDb->value("SELECT value FROM settings WHERE name='preview_mode'")==='1','HTTP fixtures explicitly disable provider execution before creating fake jobs');
    expectHttp(requestHttp('/install','POST',[])['status']===409,'installed site rejects repeated install');
    expectHttp(requestHttp('/api/v1/domains','GET',null,[],false)['status']===401,'anonymous domain API rejected');
    $page=requestHttp('/login');$csrf=csrfHttp($page['text']);
    expectHttp(requestHttp('/login','POST',$credentials)['status']===403,'login CSRF required');
    $login=requestHttp('/login','POST',$credentials,['X-CSRF-Token: '.$csrf]);expectHttp($login['status']===200,'session login succeeds');
    $dashboard=requestHttp('/');$csrf=csrfHttp($dashboard['text']);expectHttp(str_contains($dashboard['text'],'域名管理'),'dashboard renders after login');
    $sessionHeader=implode("\n",$login['headers']);preg_match('/Set-Cookie:\s*([^=;\s]+)=([^;\s]+)/i',$sessionHeader,$sessionMatch);
    expectHttp(!empty($sessionMatch[2])&&requestHttp('/api/v1/domains','GET',null,['Cookie: PHPSESSID='.$sessionMatch[2]],false)['status']===401,'an unrelated installation cookie cannot reuse the logged-in session');
    $foreignSession=bin2hex(random_bytes(20));session_id($foreignSession);session_start(['use_cookies'=>0,'cache_limiter'=>'','use_strict_mode'=>0]);
    $_SESSION=['user_id'=>1,'username'=>'preview-admin','panel_installation'=>'different-installation'];session_write_close();
    try{$foreignReplay=requestHttp('/api/v1/domains','GET',null,['Cookie: '.$sessionMatch[1].'='.$foreignSession],false);}
    finally{session_id($foreignSession);session_start(['use_cookies'=>0,'cache_limiter'=>'','use_strict_mode'=>0]);session_destroy();session_write_close();}
    expectHttp($foreignReplay['status']===401,'a forged cookie name cannot reuse another installation session file');
    $config=requestHttp('/api/v1/config');expectHttp($config['status']===200&&!str_contains($config['text'],'token_encrypted'),'config endpoint omits credentials');
    expectHttp(requestHttp('/api/admin/entries','POST',['name'=>'No CSRF','kind'=>'standard'])['status']===403,'admin mutation requires CSRF');
    $entry=requestHttp('/api/admin/entries','POST',['name'=>'标准 SaaS 入口','kind'=>'standard'],['X-CSRF-Token: '.$csrf]);expectHttp($entry['status']===201,'admin can create entry preset');
    $keyResult=requestHttp('/api/admin/tokens','POST',['name'=>'HTTP read-only test','scopes'=>['domains:read']],['X-CSRF-Token: '.$csrf]);
    expectHttp($keyResult['status']===201,'API key created through authenticated admin endpoint');
    $key=$keyResult['json']['data']['key'];$id=$keyResult['json']['data']['id'];$auth=['Authorization: Bearer '.$key];
    $issuedKeys[]=$id;
    expectHttp(requestHttp('/api/v1/domains','GET',null,$auth,false)['status']===200,'public API bearer authentication works');
    expectHttp((bool)array_filter(requestHttp('/api/v1/domains','GET',null,$auth,false)['headers'],fn($h)=>str_starts_with(strtolower($h),'x-ratelimit-limit:')),'API returns rate limit metadata');
    expectHttp(requestHttp('/api/v1/config','GET',null,$auth,false)['status']===403,'API scope restricts config reads');
    expectHttp(requestHttp('/api/v1/domains','POST',['hostnames'=>['test.example.com']],$auth,false)['status']===403,'API read-only scope restricts mutations');
    expectHttp(requestHttp('/api/admin/tokens','GET',null,$auth,false)['status']===403,'API key cannot administer other keys');
    expectHttp(requestHttp('/storage/config.php','GET',null,[],false)['status']===404,'private config not downloadable');
    requestHttp('/api/admin/tokens/'.$id.'/revoke','POST',[],['X-CSRF-Token: '.$csrf]);
    expectHttp(requestHttp('/api/v1/domains','GET',null,$auth,false)['status']===401,'revoked API key fails at HTTP boundary');
    $writeKeyResult=requestHttp('/api/admin/tokens','POST',['name'=>'HTTP lifecycle test','scopes'=>['config:read','domains:read','domains:write','logs:read']],['X-CSRF-Token: '.$csrf]);
    $writeKey=$writeKeyResult['json']['data']['key'];$writeAuth=['Authorization: Bearer '.$writeKey];
    $issuedKeys[]=$writeKeyResult['json']['data']['id'];
    $headers=['X-CSRF-Token: '.$csrf];
    require __DIR__.'/http-credentials.php';
    $temporaryEntry=requestHttp('/api/admin/entries','POST',['name'=>'Temporary HTTP entry','kind'=>'cname','targets'=>['edge.example.test']],$headers)['json']['data']['id'];
    expectHttp(requestHttp('/api/admin/entries/'.$temporaryEntry,'PATCH',['name'=>'Edited HTTP entry','targets'=>['updated.example.test']],$headers)['status']===200,'config editing works over HTTP');
    expectHttp(requestHttp('/api/admin/entries/'.$temporaryEntry,'PATCH',['active'=>false],$headers)['status']===200,'config disabling works over HTTP');
    expectHttp(requestHttp('/api/admin/entries/'.$temporaryEntry,'DELETE',[],$headers)['status']===200,'unused config deletion works over HTTP');
    expectHttp(requestHttp('/api/admin/entries/'.$temporaryEntry,'PATCH',['active'=>true],$headers)['status']===404,'deleted config cannot be edited');
    $accountResult=requestHttp('/api/admin/accounts','POST',['name'=>'HTTP fixture account','token'=>str_repeat('t',40)],$headers);
    $accountId=$accountResult['json']['data']['id'];
    $database=new Database(Config::read()['db']);$database->run("INSERT INTO zones(account_id,cf_id,name,status) VALUES(?,?,?,'active')",[$accountId,'http-zone-fixture','customer.test']);
    $profileResult=requestHttp('/api/admin/profiles','POST',['name'=>'HTTP fixture SaaS','account_id'=>$accountId,'zone_id'=>'http-zone-fixture','cname_target'=>'saas.customer.test','fallback_origin'=>'origin.customer.test'],$headers);
    $profileId=$profileResult['json']['data']['id'];
    $discovery=requestHttp('/api/admin/accounts/'.$accountId.'/saas-discovery?zone_id=http-zone-fixture');
    expectHttp($discovery['status']===200&&$discovery['json']['data']['cname_target']==='saas.customer.test'&&$discovery['json']['data']['source']==='demo','admin discovery reads isolated local setup without contacting CF');
    expectHttp(requestHttp('/api/admin/accounts/'.$accountId.'/saas-discovery?zone_id=other-zone')['status']===422,'HTTP discovery enforces account Zone ownership');
    expectHttp(requestHttp('/api/admin/accounts/'.$accountId.'/saas-discovery?zone_id=http-zone-fixture','GET',null,$writeAuth,false)['status']===403,'public API keys cannot use administrator discovery');
    $originId=(int)$database->value('SELECT id FROM origins WHERE profile_id=? ORDER BY id LIMIT 1',[$profileId]);
    $entryId=requestHttp('/api/admin/entries','POST',['name'=>'HTTP fixture entry','kind'=>'standard'],$headers)['json']['data']['id'];
    $payload=['hostnames'=>['http-'.bin2hex(random_bytes(4)).'.customer.test'],'profile_id'=>$profileId,'dns_account_id'=>$accountId,'entry_id'=>$entryId,'origin_id'=>$originId,'check'=>['path'=>'/','statuses'=>[200],'keyword'=>'']];
    $idem='http-create-'.bin2hex(random_bytes(6));
    $created=requestHttp('/api/v1/domains','POST',$payload,[...$writeAuth,'Idempotency-Key: '.$idem],false);
    expectHttp($created['status']===202&&!empty($created['json']['data']['items'][0]['job_id']),'public API creates onboarding job');
    $domainId=$created['json']['data']['items'][0]['domain_id'];
    $replayed=requestHttp('/api/v1/domains','POST',array_reverse($payload,true),[...$writeAuth,'Idempotency-Key: '.$idem],false);
    expectHttp($created['json']['data']===$replayed['json']['data'],'public API replays idempotent creation');
    expectHttp(requestHttp('/api/admin/entries/'.$entryId,'DELETE',[],$headers)['status']===409,'HTTP deletion protects referenced config');
    $pauseKey='http-pause-'.bin2hex(random_bytes(6));
    $paused=requestHttp('/api/v1/domains/'.$domainId.'/pause','POST',[],[...$writeAuth,'Idempotency-Key: '.$pauseKey],false);
    expectHttp($paused['status']===202,'public API can pause a job');
    expectHttp(requestHttp('/api/v1/domains/'.$domainId.'/pause','POST',[],[...$writeAuth,'Idempotency-Key: '.$pauseKey],false)['json']['data']===$paused['json']['data'],'public API replays pause without duplicate operation');
    expectHttp(requestHttp('/api/v1/domains/'.$domainId.'/resume','POST',[],[...$writeAuth,'Idempotency-Key: '.$pauseKey],false)['status']===409,'idempotency key cannot cross action routes');
    expectHttp(requestHttp('/api/v1/domains/'.$domainId.'/resume','POST',[],$writeAuth,false)['status']===202,'public API can resume a paused job');
    expectHttp(requestHttp('/api/v1/domains/'.$domainId.'/cancel','POST',[],$writeAuth,false)['status']===202,'public API can cancel without deleting cloud data');
    $database->run("UPDATE domains SET status='active',cf_hostname_id='http-host-fixture',hostname_status='active',ssl_status='active' WHERE id=?",[$domainId]);
    $changeInput=['check'=>['path'=>'/health','statuses'=>[200],'keyword'=>'healthy'],'expected_version'=>1];
    $changeKey='http-change-'.bin2hex(random_bytes(6));
    $changed=requestHttp('/api/v1/domains/'.$domainId,'PATCH',$changeInput,[...$writeAuth,'Idempotency-Key: '.$changeKey],false);
    expectHttp($changed['status']===202&&!empty($changed['json']['data']['change_id']),'public API queues active domain configuration change');
    expectHttp(requestHttp('/api/v1/domains/'.$domainId,'PATCH',$changeInput,[...$writeAuth,'Idempotency-Key: '.$changeKey],false)['json']['data']===$changed['json']['data'],'public API replays configuration change');
    $detail=requestHttp('/api/v1/domains/'.$domainId,'GET',null,$writeAuth,false);
    expectHttp(isset($detail['json']['data']['applied_config']['check'])&&count($detail['json']['data']['configuration_changes'])===1,'public API distinguishes applied config and pending change');
    requestHttp('/api/v1/domains/'.$domainId.'/cancel','POST',[],$writeAuth,false);
    expectHttp(requestHttp('/api/v1/domains/'.$domainId.'/rollback','POST',[],$writeAuth,false)['status']===202,'public API queues single-change rollback');
    $removeKey='http-remove-'.bin2hex(random_bytes(6));
    $removed=requestHttp('/api/v1/domains/'.$domainId,'DELETE',['mode'=>'forget'],[...$writeAuth,'Idempotency-Key: '.$removeKey],false);
    expectHttp($removed['status']===202,'public API can remove local management only');
    expectHttp(requestHttp('/api/v1/domains/'.$domainId,'DELETE',['mode'=>'forget'],[...$writeAuth,'Idempotency-Key: '.$removeKey],false)['json']['data']===$removed['json']['data'],'public API replays domain removal');
    $defaults=requestHttp('/api/admin/defaults','PATCH',['profile_id'=>$profileId,'dns_account_id'=>$accountId,'entry_id'=>$entryId,'origin_id'=>$originId,'check'=>['path'=>'/health','statuses'=>[200],'keyword'=>'healthy']],$headers);
    expectHttp($defaults['status']===200&&$defaults['json']['data']['check']['path']==='/health','persistent defaults work over HTTP');
    expectHttp(requestHttp('/api/v1/domains','GET',null,$writeAuth,false)['json']['data']['per_page']===50,'API pagination metadata is present');
    $expireResult=requestHttp('/api/admin/tokens','POST',['name'=>'Expired HTTP test','scopes'=>['domains:read']],$headers);
    $issuedKeys[]=$expireResult['json']['data']['id'];
    $database->run('UPDATE api_tokens SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE) WHERE id=?',[$expireResult['json']['data']['id']]);
    expectHttp(requestHttp('/api/v1/domains','GET',null,['Authorization: Bearer '.$expireResult['json']['data']['key']],false)['status']===401,'expired API key rejected over HTTP');
    $logs=requestHttp('/api/v1/logs?per_page=100','GET',null,$writeAuth,false);
    expectHttp($logs['status']===200&&!str_contains($logs['text'],$writeKey)&&str_contains($logs['text'],'api.request'),'API calls audited without leaking raw key');
    expectHttp(($health['json']['data']['version']??'')===\Panel\Core\Version::current(),'health reports the central application version');
    $zones=requestHttp('/api/v1/zones','GET',null,$writeAuth,false);
    $zoneId=(int)$database->value('SELECT id FROM zones WHERE account_id=? AND cf_id=?',[$accountId,'http-zone-fixture']);
    expectHttp($zones['status']===200&&count(array_filter($zones['json']['data']['items'],fn($z)=>(int)$z['id']===$zoneId))===1,'public API lists main domains with local Zone identifiers');
    $sourceName='source-'.bin2hex(random_bytes(4));$sourceKey='http-source-'.bin2hex(random_bytes(6));
    $record=requestHttp('/api/v1/zones/'.$zoneId.'/records','POST',['name'=>$sourceName,'type'=>'A','source_ip'=>'9.9.9.9'],[...$writeAuth,'Idempotency-Key: '.$sourceKey],false);
    expectHttp($record['status']===202&&!empty($record['json']['data']['items'][0]['domain_id']),'public Zone API accepts a name and source IP using defaults');
    $sourceId=$record['json']['data']['items'][0]['domain_id'];
    expectHttp(requestHttp('/api/v1/zones/'.$zoneId.'/records','POST',['name'=>$sourceName,'type'=>'A','source_ip'=>'9.9.9.9'],[...$writeAuth,'Idempotency-Key: '.$sourceKey],false)['json']['data']===$record['json']['data'],'Zone record creation replays idempotently');
    $records=requestHttp('/api/v1/zones/'.$zoneId.'/records?search='.$sourceName,'GET',null,$writeAuth,false);
    expectHttp($records['status']===200&&$records['json']['data']['total']===1&&$records['json']['data']['items'][0]['dns_records'][0]['type']==='CNAME','Zone listing exposes source and visitor DNS separately');
    expectHttp(requestHttp('/api/v1/zones/'.$zoneId.'/records','POST',['hostname'=>'foreign.test','source_ip'=>'9.9.9.9'],$writeAuth,false)['status']===422,'HTTP record creation enforces Zone membership');
    expectHttp(requestHttp('/api/admin/export','GET',null,$writeAuth,false)['status']===403,'public API keys cannot export administrator configuration');
    $export=requestHttp('/api/admin/export?zone_id='.$zoneId);
    expectHttp($export['status']===200&&!str_contains($export['text'],'token_encrypted')&&$export['json']['data']['format_version']===1,'authenticated administrator exports portable secret-free JSON');
    $document=$export['json']['data'];$bindings=[];foreach($document['accounts'] as $a){$bindings[$a['ref']]=(int)$a['ref'];}
    $jobsBefore=(int)$database->value('SELECT COUNT(*) FROM jobs');
    $preview=requestHttp('/api/admin/import','POST',['document'=>$document,'account_bindings'=>$bindings,'preview'=>true],$headers);
    expectHttp($preview['status']===200&&$preview['json']['data']['preview']&&(int)$database->value('SELECT COUNT(*) FROM jobs')===$jobsBefore,'HTTP import preview validates without persisting tasks');
    expectHttp(requestHttp('/api/admin/import','POST',['document'=>['format'=>'other']],$headers)['status']===422,'HTTP import rejects unsupported documents');
    $document['records'][0]['hostname']='import-'.$sourceName.'.customer.test';
    $importKey='http-import-'.bin2hex(random_bytes(6));$importPayload=['document'=>$document,'account_bindings'=>$bindings,'preview'=>false];
    $imported=requestHttp('/api/admin/import','POST',$importPayload,[...$headers,'Idempotency-Key: '.$importKey]);
    expectHttp($imported['status']===200&&$imported['json']['data']['new_tasks']===1,'confirmed HTTP import creates one onboarding task');
    expectHttp(requestHttp('/api/admin/import','POST',$importPayload,[...$headers,'Idempotency-Key: '.$importKey])['json']['data']===$imported['json']['data'],'confirmed import supports idempotency replay');
    requestHttp('/api/v1/domains/'.$sourceId,'DELETE',['mode'=>'forget'],$writeAuth,false);
    requestHttp('/api/v1/domains/'.$imported['json']['data']['records'][0]['domain_id'],'DELETE',['mode'=>'forget'],$writeAuth,false);
    $directPayload=['name'=>'gray-'.$sourceName,'type'=>'A','source_ip'=>'9.9.9.10','dns_mode'=>'dns_only'];
    $direct=requestHttp('/api/v1/zones/'.$zoneId.'/records','POST',$directPayload,$writeAuth,false);
    expectHttp($direct['status']===202,'HTTP Zone creation accepts gray-cloud DNS mode');
    $directId=$direct['json']['data']['items'][0]['domain_id'];
    $directDetail=requestHttp('/api/v1/domains/'.$directId,'GET',null,$writeAuth,false);
    expectHttp($directDetail['json']['data']['dns_mode']==='dns_only','HTTP domain detail reports the applied DNS mode');
    $directRows=requestHttp('/api/v1/zones/'.$zoneId.'/records?search=gray-'.$sourceName,'GET',null,$writeAuth,false);
    expectHttp($directRows['json']['data']['items'][0]['dns_records'][0]['content']==='9.9.9.10'&&!$directRows['json']['data']['items'][0]['dns_records'][0]['proxied'],'HTTP Zone projection separates direct DNS from preferred CNAME');
    expectHttp(requestHttp('/api/v1/zones/'.$zoneId.'/records','POST',[...$directPayload,'name'=>'bad-'.$sourceName,'dns_mode'=>'bad'],$writeAuth,false)['status']===422,'HTTP API validates DNS mode values');
    $database->run("UPDATE jobs SET status='completed' WHERE domain_id=?",[$directId]);$database->run("UPDATE domains SET status='active' WHERE id=?",[$directId]);
    $modeChange=requestHttp('/api/v1/domains/'.$directId,'PATCH',['dns_mode'=>'proxied','expected_version'=>1],$writeAuth,false);
    expectHttp($modeChange['status']===202&&!empty($modeChange['json']['data']['change_id']),'HTTP mode switch works for ordinary DNS records without a SaaS hostname');
    $pendingMode=requestHttp('/api/v1/domains/'.$directId,'GET',null,$writeAuth,false);
    expectHttp($pendingMode['json']['data']['dns_mode']==='dns_only'&&json_decode($pendingMode['json']['data']['configuration_changes'][0]['to_config'],true)['dns_mode']==='proxied','HTTP detail distinguishes current mode from its pending target');
    $modeDoc=requestHttp('/api/admin/export?zone_id='.$zoneId)['json']['data'];
    expectHttp($modeDoc['format_version']===2,'HTTP export protects DNS modes with portable format version two');
    $bulkKeyResult=requestHttp('/api/admin/tokens','POST',['name'=>'HTTP bulk test','scopes'=>['domains:read','domains:write']],$headers)['json']['data'];$issuedKeys[]=$bulkKeyResult['id'];$bulkAuth=['Authorization: Bearer '.$bulkKeyResult['key']];
    $bulkRecords=[];$bulkSetup=true;
    foreach (['a'=>'dns_only','b'=>'proxied','c'=>'dns_only'] as $suffix=>$mode) {
        $createdBulk=requestHttp('/api/v1/zones/'.$zoneId.'/records','POST',['name'=>'bulk-'.$suffix.'-'.$sourceName,'type'=>'A','source_ip'=>'9.9.9.55','dns_mode'=>$mode],$bulkAuth,false);
        $bulkSetup=$bulkSetup&&$createdBulk['status']===202;$bulkId=(int)$createdBulk['json']['data']['items'][0]['domain_id'];$bulkRecords[]=['id'=>$bulkId,'expected_version'=>1];
        $database->run("UPDATE jobs SET status='completed' WHERE domain_id=?",[$bulkId]);$database->run("UPDATE domains SET status='active' WHERE id=?",[$bulkId]);
    }
    expectHttp($bulkSetup,'HTTP bulk fixtures accept all DNS modes');
    $bulkPayload=['action'=>'set_mode','dns_mode'=>'proxied','preview'=>true,'records'=>[...$bulkRecords,['id'=>(int)$directId,'expected_version'=>1],['id'=>9999999,'expected_version'=>1]]];
    $bulkPath='/api/v1/zones/'.$zoneId.'/records/bulk';$bulkJobCount=(int)$database->value('SELECT COUNT(*) FROM jobs');
    $bulkPreview=requestHttp($bulkPath,'POST',$bulkPayload,$bulkAuth,false);
    expectHttp($bulkPreview['status']===200&&$bulkPreview['json']['data']['summary']['ready']===2&&$bulkPreview['json']['data']['summary']['unchanged']===1&&$bulkPreview['json']['data']['summary']['failed']===2,'HTTP bulk preview returns per-record availability and failures');
    expectHttp((int)$database->value('SELECT COUNT(*) FROM jobs')===$bulkJobCount,'HTTP bulk preview leaves the job queue unchanged');
    $bulkReadKey=requestHttp('/api/admin/tokens','POST',['name'=>'HTTP bulk read test','scopes'=>['domains:read']],$headers)['json']['data'];$issuedKeys[]=$bulkReadKey['id'];
    expectHttp(requestHttp($bulkPath,'POST',$bulkPayload,['Authorization: Bearer '.$bulkReadKey['key']],false)['status']===403,'read-only API keys cannot preview or submit bulk writes');
    $bulkPayload['preview']=false;$bulkKey='http-bulk-'.bin2hex(random_bytes(6));
    $bulkSubmitted=requestHttp($bulkPath,'POST',$bulkPayload,[...$bulkAuth,'Idempotency-Key: '.$bulkKey],false);
    expectHttp($bulkSubmitted['status']===202&&$bulkSubmitted['json']['data']['summary']['queued']===2&&$bulkSubmitted['json']['data']['summary']['failed']===2,'HTTP bulk submission queues valid records despite individual failures');
    expectHttp(requestHttp($bulkPath,'POST',$bulkPayload,[...$bulkAuth,'Idempotency-Key: '.$bulkKey],false)['json']['data']===$bulkSubmitted['json']['data'],'HTTP bulk replay preserves the batch identifier and original job results');
    expectHttp(requestHttp($bulkPath,'POST',[...$bulkPayload,'dns_mode'=>'dns_only'],[...$bulkAuth,'Idempotency-Key: '.$bulkKey],false)['status']===409,'HTTP bulk idempotency is bound to the target mode');
    expectHttp(requestHttp($bulkPath,'POST',[...$bulkPayload,'records'=>array_fill(0,51,$bulkRecords[0])],$bulkAuth,false)['status']===422,'HTTP bulk endpoint enforces its fifty-record limit');
    $bulkDelete=['action'=>'delete','preview'=>true,'records'=>[$bulkRecords[1]]];
    $bulkDeletePreview=requestHttp($bulkPath,'POST',$bulkDelete,$bulkAuth,false);
    expectHttp($bulkDeletePreview['status']===200&&$bulkDeletePreview['json']['data']['mode']==='delete_records'&&$bulkDeletePreview['json']['data']['summary']['ready']===1,'HTTP deletion preview explicitly selects current-record deletion');
    $bulkDeleteKey='http-bulk-delete-'.bin2hex(random_bytes(6));$bulkDelete['preview']=false;
    $bulkDeleteResult=requestHttp($bulkPath,'POST',$bulkDelete,[...$bulkAuth,'Idempotency-Key: '.$bulkDeleteKey],false);
    expectHttp($bulkDeleteResult['status']===202&&$bulkDeleteResult['json']['data']['summary']['queued']===1&&$database->value('SELECT deleted_at FROM domains WHERE id=?',[$bulkRecords[1]['id']])===null,'HTTP bulk deletion stays managed until the cloud task completes');
    expectHttp(requestHttp($bulkPath,'POST',$bulkDelete,[...$bulkAuth,'Idempotency-Key: '.$bulkDeleteKey],false)['json']['data']===$bulkDeleteResult['json']['data'],'HTTP bulk deletion replay cannot queue duplicate removals');
    $bulkForget=requestHttp($bulkPath,'POST',['action'=>'delete','mode'=>'forget','preview'=>false,'records'=>$bulkRecords],$bulkAuth,false);
    expectHttp($bulkForget['status']===202&&$bulkForget['json']['data']['summary']['removed']===3,'HTTP bulk forget archives selected fixture records and cancels their pending jobs');
    requestHttp('/api/v1/domains/'.$directId,'DELETE',['mode'=>'forget'],$writeAuth,false);
    $pool=requestHttp('/api/admin/entries','POST',['name'=>'HTTP preferred IP group','kind'=>'ip','targets'=>['1.1.1.1','8.8.8.8','2606:4700:4700::1111']],$headers);
    expectHttp($pool['status']===201,'admin can save a preferred IP group with mixed address families');
    $poolName='pool-'.bin2hex(random_bytes(4));$poolRequest=['name'=>$poolName,'type'=>'A','source_ip'=>'9.9.9.77','entry_id'=>$pool['json']['data']['id']];
    $poolRecord=requestHttp('/api/v1/zones/'.$zoneId.'/records','POST',$poolRequest,$writeAuth,false);
    expectHttp($poolRecord['status']===202,'record API accepts one source IP with a separate preferred IP group');
    $poolList=requestHttp('/api/v1/zones/'.$zoneId.'/records?search='.$poolName,'GET',null,$writeAuth,false)['json']['data']['items'][0];
    expectHttp(count($poolList['dns_records'])===3&&$poolList['source']==='9.9.9.77'&&count(array_filter($poolList['dns_records'],fn($r)=>$r['type']==='AAAA'))===1,'public record view exposes every preferred IP without mixing in the source IP');
    $cnameName='cname-'.bin2hex(random_bytes(4));$cnameRequest=['name'=>$cnameName,'type'=>'CNAME','source_hostname'=>'Origin.backend.test.','entry_id'=>$entryId];
    $cnameRecord=requestHttp('/api/v1/zones/'.$zoneId.'/records','POST',$cnameRequest,$writeAuth,false);
    expectHttp($cnameRecord['status']===202,'public API accepts a manually selected CNAME origin');
    $cnameDetail=requestHttp('/api/v1/domains/'.$cnameRecord['json']['data']['items'][0]['domain_id'],'GET',null,$writeAuth,false);
    expectHttp($cnameDetail['json']['data']['applied_config']['origin']['source_hostname']==='origin.backend.test','HTTP CNAME origin plan preserves the normalized source address');
    expectHttp(requestHttp('/api/v1/zones/'.$zoneId.'/records','POST',$cnameRequest+['source_ip'=>'9.9.9.77'],$writeAuth,false)['status']===422,'HTTP source IP and CNAME fields are mutually exclusive');
    expectHttp(requestHttp('/api/admin/export?zone_id='.$zoneId)['json']['data']['format_version']===3,'HTTP export advertises the CNAME origin format version');
    $aliasPrefix='entry-'.bin2hex(random_bytes(4));
    $aliasInput=['name'=>'HTTP shared entry','kind'=>'ip','targets'=>['1.1.1.1','2606:4700:4700::1111'],'zone_id'=>$zoneId,'record_name'=>$aliasPrefix];
    $aliasResult=requestHttp('/api/admin/entries','POST',$aliasInput,$headers);
    expectHttp($aliasResult['status']===201,'admin API saves a custom entry prefix with a selected main domain');
    $aliasId=$aliasResult['json']['data']['id'];$aliasHostname=$aliasPrefix.'.customer.test';
    expectHttp(requestHttp('/api/admin/entries','POST',[...$aliasInput,'record_name'=>'another-'.$aliasPrefix],$writeAuth,false)['status']===403,'public API keys cannot create administrator entry configurations');
    expectHttp(requestHttp('/api/admin/entries','POST',$aliasInput,$headers)['status']===409,'duplicate shared entry hostname is rejected over HTTP');
    expectHttp(requestHttp('/api/admin/entries','POST',[...$aliasInput,'record_name'=>'customer.test'],$headers)['status']===422,'HTTP entry configuration rejects the Zone apex');
    expectHttp(requestHttp('/api/admin/entries','POST',[...$aliasInput,'record_name'=>'bad?prefix'],$headers)['status']===422,'HTTP entry configuration validates custom prefixes');
    $aliasRecord=requestHttp('/api/v1/zones/'.$zoneId.'/records','POST',['name'=>'business-'.$aliasPrefix,'type'=>'A','source_ip'=>'9.9.9.91','entry_id'=>$aliasId],$writeAuth,false);
    expectHttp($aliasRecord['status']===202,'public record API accepts a custom shared entry separately from the source IP');
    $aliasRows=requestHttp('/api/v1/zones/'.$zoneId.'/records?search=business-'.$aliasPrefix,'GET',null,$writeAuth,false)['json']['data']['items'];
    expectHttp(count($aliasRows)===1&&count($aliasRows[0]['dns_records'])===1&&$aliasRows[0]['dns_records'][0]['type']==='CNAME'&&$aliasRows[0]['dns_records'][0]['content']===$aliasHostname,'HTTP business record projection is one CNAME to the shared entry even for a multi-IP target');
    expectHttp($aliasRows[0]['source']==='9.9.9.91'&&$aliasRows[0]['applied_config']['entry']['zone_name']==='customer.test','HTTP record snapshots preserve separate origin and entry DNS placement');
    expectHttp(requestHttp('/api/admin/entries/'.$aliasId,'PATCH',['record_name'=>'moved-'.$aliasPrefix],$headers)['status']===409,'HTTP editing cannot rename a shared entry already referenced by a business record');
    expectHttp(requestHttp('/api/v1/zones/'.$zoneId.'/records','POST',['name'=>$aliasPrefix,'source_ip'=>'9.9.9.91','entry_id'=>$entryId,'dns_mode'=>'dns_only'],$writeAuth,false)['status']===409,'HTTP DNS record creation cannot overwrite another record shared entry');
    $aliasExport=requestHttp('/api/admin/export?zone_id='.$zoneId)['json']['data'];
    expectHttp($aliasExport['format_version']===4&&count(array_filter($aliasExport['entries'],fn($entry)=>($entry['alias']['hostname']??null)===$aliasHostname))===1,'HTTP export preserves shared entry aliases in portable format four');
    $aliasJobs=(int)$database->value('SELECT COUNT(*) FROM jobs');
    $aliasPreview=requestHttp('/api/admin/import','POST',['document'=>$aliasExport,'account_bindings'=>$bindings,'preview'=>true],$headers);
    expectHttp($aliasPreview['status']===200&&$aliasPreview['json']['data']['preview']&&(int)$database->value('SELECT COUNT(*) FROM jobs')===$aliasJobs,'HTTP format-four import preview reuses entry plans without persisting new work');
    requestHttp('/api/v1/domains/'.$aliasRecord['json']['data']['items'][0]['domain_id'],'DELETE',['mode'=>'forget'],$writeAuth,false);
    requestHttp('/api/admin/entries/'.$aliasId,'DELETE',[],$headers);
    foreach([$poolRecord,$cnameRecord] as $fixture){requestHttp('/api/v1/domains/'.$fixture['json']['data']['items'][0]['domain_id'],'DELETE',['mode'=>'forget'],$writeAuth,false);}
    require __DIR__.'/http-inventory.php';
    require __DIR__.'/http-existing-dns.php';
    require __DIR__.'/http-dns-actions.php';
    requestHttp('/api/admin/tokens/'.$writeKeyResult['json']['data']['id'].'/revoke','POST',[],$headers);
    expectHttp(requestHttp('/assets/app.css')['status']===200,'stylesheet available');
    expectHttp(requestHttp('/assets/app.js')['status']===200,'script available');
    echo "\n$checks HTTP checks passed.\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");$httpFailure=true;}
finally { foreach($issuedKeys as $issuedKey) { try{requestHttp('/api/admin/tokens/'.$issuedKey.'/revoke','POST',[],['X-CSRF-Token: '.$csrf]);}catch(Throwable){} } }
if(!empty($httpFailure)){exit(1);}

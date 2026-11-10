<?php
// SPDX-License-Identifier: AGPL-3.0-only
$reviewKey=requestHttp('/api/admin/tokens','POST',['name'=>'Public API contract review','scopes'=>['config:read','domains:read','domains:write','logs:read']],$headers)['json']['data'];
$issuedKeys[]=$reviewKey['id'];$reviewAuth=['Authorization: Bearer '.$reviewKey['key']];
$reviewConfig=requestHttp('/api/v1/config','GET',null,$reviewAuth,false);
expectHttp($reviewConfig['status']===200&&$reviewConfig['json']['data']['version']===\Panel\Core\Version::current(),'public config version matches release and excludes authentication material');
expectHttp(!str_contains($reviewConfig['text'],'token_encrypted')&&!str_contains($reviewConfig['text'],$reviewKey['key']),'public config excludes stored credentials and caller API key');
$spec=requestHttp('/openapi.json','GET',null,[],false);
expectHttp($spec['status']===200&&($spec['json']['openapi']??'')==='3.1.1'&&($spec['json']['info']['version']??'')===\Panel\Core\Version::current(),'anonymous OpenAPI document is downloadable and versioned');
$reviewCreate=requestHttp('/api/v1/domains','POST',[...$payload,'hostnames'=>['contract-'.bin2hex(random_bytes(5)).'.customer.test']],$reviewAuth,false);
expectHttp($reviewCreate['status']===202&&!empty($reviewCreate['json']['data']['items'][0]['job_id']),'API review creates an isolated onboarding task');
$reviewDomain=(int)$reviewCreate['json']['data']['items'][0]['domain_id'];$reviewJob=(int)$reviewCreate['json']['data']['items'][0]['job_id'];
$reviewJobResponse=requestHttp('/api/v1/jobs/'.$reviewJob,'GET',null,$reviewAuth,false);
expectHttp($reviewJobResponse['status']===200&&(int)$reviewJobResponse['json']['data']['domain_id']===$reviewDomain,'public job endpoint returns the exact queued task');
foreach(['pause','resume','pause','retry','cancel','diagnose','cancel','rollback'] as $reviewAction){expectHttp(requestHttp('/api/v1/domains/'.$reviewDomain.'/'.$reviewAction,'POST',[],$reviewAuth,false)['status']===202,'public task control accepts supported transition: '.$reviewAction);}
$filteredLogs=requestHttp('/api/v1/logs?domain_id='.$reviewDomain.'&event=domain.&page=1&per_page=2','GET',null,$reviewAuth,false);
expectHttp($filteredLogs['status']===200&&count($filteredLogs['json']['data']['items'])<=2&&!array_filter($filteredLogs['json']['data']['items'],fn($row)=>(int)$row['domain_id']!==$reviewDomain||!str_starts_with($row['event'],'domain.')),'public log filters and pagination return only the requested domain events');
expectHttp(requestHttp('/api/v1/logs?since=invalid-date','GET',null,$reviewAuth,false)['status']===422,'public logs reject invalid date filters');
foreach(['/api/v1/jobs/999999999','/api/v1/domains/999999999','/api/v1/dns-operations/999999999'] as $missingPath){expectHttp(requestHttp($missingPath,'GET',null,$reviewAuth,false)['status']===404,'public missing resource returns 404: '.$missingPath);}
expectHttp(requestHttp('/api/v1/config','POST',[],$reviewAuth,false)['status']===404,'unsupported public method returns the documented error');
$database->run("UPDATE dns_operations SET status='failed' WHERE id=?",[$operationId]);
$retryDns=requestHttp('/api/v1/dns-operations/'.$operationId.'/retry','POST',[],$reviewAuth,false);
expectHttp($retryDns['status']===202&&(int)$retryDns['json']['data']['operation_id']===$operationId,'public API retries failed DNS operations using the original baseline');
expectHttp(requestHttp('/api/v1/dns-operations/'.$operationId.'/retry','POST',[],$reviewAuth,false)['status']===409,'DNS retry rejects a second transition while already queued');
requestHttp('/api/v1/domains/'.$reviewDomain,'DELETE',['mode'=>'forget'],$reviewAuth,false);
$logOnly=requestHttp('/api/admin/tokens','POST',['name'=>'Public logs scope review','scopes'=>['logs:read']],$headers)['json']['data'];$issuedKeys[]=$logOnly['id'];
expectHttp(requestHttp('/api/v1/logs','GET',null,['Authorization: Bearer '.$logOnly['key']],false)['status']===200&&requestHttp('/api/v1/domains','GET',null,['Authorization: Bearer '.$logOnly['key']],false)['status']===403,'logs-only API key reads logs but cannot read domains');
$limit=max(10,min(1000,(int)(getenv('API_RATE_LIMIT')?:60)));$window=intdiv(time(),60);
foreach([$window,$window+1] as $bucketWindow){$database->run('INSERT INTO api_rate_limits(bucket,hits,expires_at) VALUES(?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 2 MINUTE)) ON DUPLICATE KEY UPDATE hits=VALUES(hits)',['api:'.$logOnly['id'].':'.$bucketWindow,$limit]);}
$limited=requestHttp('/api/v1/logs','GET',null,['Authorization: Bearer '.$logOnly['key']],false);
expectHttp($limited['status']===429&&($limited['json']['error']['code']??'')==='rate_limited'&&(bool)array_filter($limited['headers'],fn($line)=>preg_match('/^Retry-After: (?:[1-9]|[1-5]\d|60)\s*$/i',$line)),'API rate limiting returns 429, stable code and bounded Retry-After');
requestHttp('/api/admin/tokens/'.$reviewKey['id'].'/revoke','POST',[],$headers);
requestHttp('/api/admin/tokens/'.$logOnly['id'].'/revoke','POST',[],$headers);

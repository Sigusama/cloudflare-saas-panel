<?php
// SPDX-License-Identifier: AGPL-3.0-only
$zoneAccountToken=requestHttp('/api/admin/tokens','POST',['name'=>'DNS account filter checks','scopes'=>['domains:read']],$headers)['json']['data'];
$issuedKeys[]=$zoneAccountToken['id'];$zoneAccountReadAuth=['Authorization: Bearer '.$zoneAccountToken['key']];
$accountFixture=requestHttp('/api/admin/accounts','POST',['name'=>'DNS account selection fixture','token'=>bin2hex(random_bytes(20))],$headers);
$accountFixtureId=(int)$accountFixture['json']['data']['id'];
$sameNamedZone=$database->insert("INSERT INTO zones(account_id,cf_id,name,status) VALUES(?,?,?,'active')",[$accountFixtureId,'http-other-account-'.bin2hex(random_bytes(6)),'customer.test']);
$allAccountDomains=requestHttp('/api/v1/zones','GET',null,$zoneAccountReadAuth,false);
expectHttp($allAccountDomains['status']===200&&(bool)array_filter($allAccountDomains['json']['data']['items'],fn($zone)=>(int)$zone['id']===$sameNamedZone)&&(bool)array_filter($allAccountDomains['json']['data']['items'],fn($zone)=>(int)$zone['id']===$zoneId),'HTTP default domain list includes independent account sources without merging same names');
$filteredDomains=requestHttp('/api/v1/zones?account_id='.$accountFixtureId,'GET',null,$zoneAccountReadAuth,false);
expectHttp($filteredDomains['status']===200&&count($filteredDomains['json']['data']['items'])===1&&(int)$filteredDomains['json']['data']['items'][0]['id']===$sameNamedZone,'HTTP API filters main domains by the requested account');
$originalAccountDomains=requestHttp('/api/v1/zones?account_id='.$accountId,'GET',null,$zoneAccountReadAuth,false);
expectHttp($originalAccountDomains['status']===200&&!array_filter($originalAccountDomains['json']['data']['items'],fn($zone)=>(int)$zone['account_id']!==$accountId),'HTTP account selection does not leak the other account into results');
expectHttp(requestHttp('/api/v1/zones?account_id=999999999','GET',null,$zoneAccountReadAuth,false)['json']['data']['items']===[],'HTTP unknown account filter stays empty');
foreach(['0','abc','1.5','%5B1%5D'] as $badAccount){expectHttp(requestHttp('/api/v1/zones?account_id='.$badAccount,'GET',null,$zoneAccountReadAuth,false)['status']===422,'HTTP invalid account filter rejected: '.$badAccount);}
expectHttp(requestHttp('/api/v1/zones?account_id%5B%5D=1','GET',null,$zoneAccountReadAuth,false)['status']===422,'HTTP account filter rejects array-shaped query input');
$database->run('UPDATE accounts SET deleted_at=UTC_TIMESTAMP() WHERE id=?',[$accountFixtureId]);
expectHttp(requestHttp('/api/v1/zones?account_id='.$accountFixtureId,'GET',null,$zoneAccountReadAuth,false)['json']['data']['items']===[],'HTTP deleted account has no selectable domains');
requestHttp('/api/admin/tokens/'.$zoneAccountToken['id'].'/revoke','POST',[],$headers);

<?php
// SPDX-License-Identifier: AGPL-3.0-only
use Panel\Services\ZoneRecords;

$accountZones=new ZoneRecords($db);$originalZone=$accountZones->zones()['items'][0];
$otherZoneAccount=$configs->save('accounts',['name'=>'Independent DNS account','token'=>bin2hex(random_bytes(20))],'test');
$otherZone=$db->insert("INSERT INTO zones(account_id,cf_id,name,status) VALUES(?,?,?,'active')",[$otherZoneAccount,'other-account-zone','other-account.test']);
$allAccountZones=$accountZones->zones()['items'];
check(count(array_filter($allAccountZones,fn($zone)=>(int)$zone['id']===$otherZone))===1,'all account domain picker includes distinct domains from each account');
$onlyOther=$accountZones->zones($otherZoneAccount)['items'];
check(count($onlyOther)===1&&(int)$onlyOther[0]['id']===$otherZone&&(int)$onlyOther[0]['account_id']===$otherZoneAccount,'account filter selects the exact account and local Zone identity');
$onlyOriginal=$accountZones->zones((int)$originalZone['account_id'])['items'];
check(!array_filter($onlyOriginal,fn($zone)=>(int)$zone['account_id']!==(int)$originalZone['account_id'])&&!in_array($otherZone,array_map('intval',array_column($onlyOriginal,'id')),true),'domains from another account are outside the selected account filter');
check($accountZones->zones(999999999)['items']===[],'unknown account filter returns an empty list instead of all domains');
$db->run('UPDATE accounts SET active=0 WHERE id=?',[$otherZoneAccount]);
check(count($accountZones->zones($otherZoneAccount)['items'])===1&&(int)$accountZones->zones($otherZoneAccount)['items'][0]['account_active']===0,'disabled account domains remain visible with their actual availability');
$db->run('UPDATE accounts SET deleted_at=UTC_TIMESTAMP() WHERE id=?',[$otherZoneAccount]);
check($accountZones->zones($otherZoneAccount)['items']===[]&&!array_filter($accountZones->zones()['items'],fn($zone)=>(int)$zone['account_id']===$otherZoneAccount),'deleted accounts are excluded from global and account-filtered domain lists');
rejects(fn()=>$accountZones->zones(0),422,'invalid account filter cannot silently select every account');

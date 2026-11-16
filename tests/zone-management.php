<?php
// SPDX-License-Identifier: AGPL-3.0-only
use Panel\Services\ZoneManagement;

final class ZoneCreationTransport extends \Panel\Cloudflare\CloudflareClient
{
    public array $sent=[],$zones=[];
    public bool $denyAccounts=false,$denyCreate=false,$loseCreate=false,$unknownCreate=false,$wrongOwner=false,$networkAccounts=false;
    public string $owner='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private int $sequence=0;
    public function __construct(){parent::__construct('zone-creation-test-only');}
    protected function send(string $method,string $url,array $headers,?array $body):array
    {
        $path=parse_url($url,PHP_URL_PATH);$path=substr($path,strlen('/client/v4'));parse_str(parse_url($url,PHP_URL_QUERY)??'',$query);
        $this->sent[]=compact('method','path','body','query');$result=[];$info=null;
        if($path==='/accounts'){
            if($this->networkAccounts){return [false,0,28,null];}
            if($this->denyAccounts){return [json_encode(['success'=>false,'errors'=>[['message'=>'Account list not permitted']]]),403,0,null];}
            $result=[['id'=>$this->owner,'name'=>'Target account'],['id'=>str_repeat('b',32),'name'=>'Second account']];
            $result=array_slice($result,(int)($query['page']??1)-1,1);$info=['page'=>(int)($query['page']??1),'per_page'=>1,'total_count'=>2];
        }elseif($path==='/zones'&&$method==='GET'){
            $result=array_values(array_filter($this->zones,static fn($zone)=>(!isset($query['name'])||$zone['name']===$query['name'])&&(!isset($query['account_id'])||$zone['account']['id']===$query['account_id'])));$info=['total_pages'=>1];
        }elseif($path==='/zones'&&$method==='POST'){
            if($this->denyCreate){return [json_encode(['success'=>false,'errors'=>[['message'=>'Zone creation not permitted']]]),403,0,null];}
            if($this->unknownCreate){return [false,0,28,null];}
            $id=str_pad(dechex(++$this->sequence),32,'0',STR_PAD_LEFT);
            $result=['id'=>$id,'name'=>$body['name'],'account'=>['id'=>$this->wrongOwner?str_repeat('b',32):$body['account']['id'],'name'=>'Target account'],'status'=>'pending','type'=>'full','name_servers'=>['alice.ns.cloudflare.com','bob.ns.cloudflare.com']];
            $this->zones[$id]=$result;
            if($this->loseCreate){$this->loseCreate=false;return [false,0,28,null];}
        }elseif(preg_match('#^/zones/([a-f0-9]{32})$#D',$path,$match)){$result=$this->zones[$match[1]]??[];}
        else{throw new RuntimeException('Unexpected zone management request '.$method.' '.$path);}
        return [json_encode(['success'=>true,'result'=>$result]+($info?['result_info'=>$info]:[])),200,0,null];
    }
    public function creates():int{return count(array_filter($this->sent,static fn($request)=>$request['method']==='POST'));}
}

$zoneCredential=$configs->save('accounts',['name'=>'Zone creation fixture','token'=>str_repeat('q',40)],'test');
$zoneTransport=new ZoneCreationTransport();$zoneCloud=new \Panel\Services\Cloud($db,$crypto,fn()=>$zoneTransport);$zoneManager=new ZoneManagement($db,$zoneCloud);
$zonePreview=$db->value("SELECT value FROM settings WHERE name='preview_mode'");
$db->run("INSERT INTO settings(name,value) VALUES('preview_mode','0') ON DUPLICATE KEY UPDATE value='0'");
try{
    $targets=$zoneManager->accounts($zoneCredential);
    check(count($targets['items'])===2,'Cloudflare target account discovery reads all total_count pages');
    $input=['name'=>' New-Zone.Example.Test. ','cf_account_id'=>str_repeat('a',32)];
    $created=$zoneManager->create($zoneCredential,$input,'test');
    check($created['created']&&$created['name']==='new-zone.example.test'&&$created['status']==='pending'&&count($created['name_servers'])===2,'zone creation normalizes names and returns pending status with assigned NS');
    $post=array_values(array_filter($zoneTransport->sent,fn($request)=>$request['method']==='POST'))[0];
    check($post['body']===['account'=>['id'=>str_repeat('a',32)],'name'=>'new-zone.example.test','type'=>'full'],'zone creation sends explicit remote account and full setup without paid subscription changes');
    check($db->value('SELECT status FROM zones WHERE id=?',[$created['id']])==='pending','new Cloudflare zone is saved locally without claiming activation');
    $again=$zoneManager->create($zoneCredential,$input,'test');
    check(!$again['created']&&$again['id']===$created['id']&&$zoneTransport->creates()===1,'repeated same-account domain creation reuses the exact existing zone');
    $other=$zoneManager->create($zoneCredential,['name'=>$created['name'],'cf_account_id'=>str_repeat('b',32)],'test');
    check($other['id']!==$created['id']&&$other['cf_account_id']===str_repeat('b',32),'same-name zone in a different Cloudflare account is not silently reused');
    $zoneTransport->zones[$created['cf_id']]['status']='active';$fresh=$zoneManager->details($created['id']);
    check($fresh['status']==='active'&&$db->value('SELECT status FROM zones WHERE id=?',[$created['id']])==='active','read-only NS refresh updates the local activation status');
    $zoneTransport->denyAccounts=true;$fallback=$zoneManager->accounts($zoneCredential);
    check(count($fallback['items'])===2,'credentials without account-list permission discover target accounts from readable zones');
    $savedZones=$zoneTransport->zones;$zoneTransport->zones=[];
    check($zoneManager->accounts($zoneCredential)['items']===[],'missing discovery permissions or empty accounts allow manual Account ID entry');
    $zoneTransport->zones=$savedZones;$zoneTransport->networkAccounts=true;
    rejects(fn()=>$zoneManager->accounts($zoneCredential),503,'network failure in account discovery remains visible instead of claiming an empty list');$zoneTransport->networkAccounts=false;
    $before=count($zoneTransport->sent);
    foreach(['https://example.test','*.example.test','1.1.1.1',['example.test']] as $bad){rejects(fn()=>$zoneManager->create($zoneCredential,['name'=>$bad,'cf_account_id'=>str_repeat('a',32)],'test'),422,'invalid zone name rejected before provider access');}
    foreach(['1',str_repeat('g',32),['a']] as $bad){rejects(fn()=>$zoneManager->create($zoneCredential,['name'=>'invalid.example.test','cf_account_id'=>$bad],'test'),422,'invalid remote Account ID rejected before provider access');}
    check(count($zoneTransport->sent)===$before,'input validation makes no Cloudflare requests');
    $zoneTransport->loseCreate=true;$lost=$zoneManager->create($zoneCredential,['name'=>'response-lost.example.test','cf_account_id'=>str_repeat('a',32)],'test');
    check($lost['reconciled']&&!$lost['created']&&$lost['name']==='response-lost.example.test','lost creation response is reconciled by querying the exact account and domain');
    $zoneTransport->unknownCreate=true;
    rejects(fn()=>$zoneManager->create($zoneCredential,['name'=>'unknown.example.test','cf_account_id'=>str_repeat('a',32)],'test'),503,'unconfirmed creation remains an error with no local success record');$zoneTransport->unknownCreate=false;
    check(!$db->value('SELECT id FROM zones WHERE name=?',['unknown.example.test']),'unknown creation result does not create a local zone');
    $zoneTransport->denyCreate=true;
    rejects(fn()=>$zoneManager->create($zoneCredential,['name'=>'forbidden.example.test','cf_account_id'=>str_repeat('a',32)],'test'),422,'Cloudflare creation permission failure is reported');$zoneTransport->denyCreate=false;
    $zoneTransport->wrongOwner=true;
    rejects(fn()=>$zoneManager->create($zoneCredential,['name'=>'wrong-account.example.test','cf_account_id'=>str_repeat('a',32)],'test'),503,'provider response cannot silently create a zone under a different target account');$zoneTransport->wrongOwner=false;
    check(!$db->value('SELECT id FROM zones WHERE name=?',['wrong-account.example.test']),'mismatched remote account is not persisted');
    $zoneAuth=new \Panel\Auth\Auth($db);$key='zone-test-'.bin2hex(random_bytes(5));$operation=fn()=>$zoneManager->create($zoneCredential,['name'=>'idempotent.example.test','cf_account_id'=>str_repeat('a',32)],'test');
    $first=$zoneAuth->idempotent('zone-test',$key,['name'=>'idempotent.example.test'],$operation,'POST /api/admin/zones');$postCount=$zoneTransport->creates();
    check($zoneAuth->idempotent('zone-test',$key,['name'=>'idempotent.example.test'],$operation,'POST /api/admin/zones')===$first&&$zoneTransport->creates()===$postCount,'idempotency replay returns original NS and result without another provider write');
    rejects(fn()=>$zoneAuth->idempotent('zone-test',$key,['name'=>'changed.example.test'],$operation,'POST /api/admin/zones'),409,'zone creation idempotency key cannot be reused for different input');
    $transportCount=count($zoneTransport->sent);$db->run("UPDATE settings SET value='1' WHERE name='preview_mode'");
    rejects(fn()=>$zoneManager->create($zoneCredential,$input,'test'),409,'demo mode prevents all zone creation writes');
    rejects(fn()=>$zoneManager->details($created['id']),409,'demo mode does not fetch real Cloudflare NS');
    check($zoneManager->accounts($zoneCredential)['demo']&&count($zoneTransport->sent)===$transportCount,'demo account discovery and rejected actions make no external requests');
    $db->run("UPDATE settings SET value='0' WHERE name='preview_mode'");$db->run('UPDATE accounts SET active=0 WHERE id=?',[$zoneCredential]);
    rejects(fn()=>$zoneManager->create($zoneCredential,$input,'test'),422,'disabled credentials cannot create a Cloudflare zone');
    check(!array_filter($zoneTransport->sent,fn($request)=>!in_array($request['path'],['/accounts','/zones','/zones/'.$created['cf_id']],true)),'zone management does not edit DNS records, SaaS resources or subscriptions');
    rejects(fn()=>$zoneTransport->request('POST','/accounts',[]),500,'new account read support cannot create Cloudflare accounts');
}finally{
    if($zonePreview===false){$db->run("DELETE FROM settings WHERE name='preview_mode'");}else{$db->run("UPDATE settings SET value=? WHERE name='preview_mode'",[$zonePreview]);}
}

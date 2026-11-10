<?php
// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

use Panel\Services\{CloudflareCredential, Cloud};
use Panel\Core\Validator;
use Panel\Cloudflare\CloudflareClient;

$globalKey='cfk_'.str_repeat('g',48);$globalEmail='global-auth@example.test';
$globalInput=['name'=>'Global auth fixture','auth_type'=>'global_key','email'=>$globalEmail,'global_key'=>$globalKey];
$credential=CloudflareCredential::input($globalInput);
check(CloudflareCredential::stored('global_key',CloudflareCredential::pack($credential))===$credential,'Global Key and email payload roundtrips');
check(CloudflareCredential::input(['token'=>''],$credential)===$credential,'blank unchanged credentials retain the stored key');
rejects(fn()=>CloudflareCredential::input(['auth_type'=>'api_token'],$credential),422,'switching Global Key to Token requires a new credential');
rejects(fn()=>CloudflareCredential::input(['auth_type'=>'global_key','email'=>''],$credential),422,'explicit empty account email rejected');
rejects(fn()=>CloudflareCredential::input([...$globalInput,'email'=>"test@example.test\r\nInjected: yes"]),422,'Global Key email rejects header injection');
rejects(fn()=>CloudflareCredential::input([...$globalInput,'global_key'=>"bad\r\nkey"]),422,'Global Key rejects header injection');
rejects(fn()=>CloudflareCredential::input([...$globalInput,'token'=>str_repeat('t',40)]),422,'mixed authentication credentials rejected');
foreach(['cfut_'.str_repeat('t',48),'cfat_'.str_repeat('a',48),str_repeat('t',40)] as $value){check(CloudflareCredential::input(['token'=>$value])['secret']===$value,'current and legacy Token formats accepted');}
check(CloudflareCredential::input([...$globalInput,'global_key'=>str_repeat('a',37)])['secret']===str_repeat('a',37),'legacy Global Key format accepted');

class CredentialTransport extends CloudflareClient
{
    public array $sent=[];
    public array $reply=['success'=>true,'result'=>[]];
    protected function send(string $method,string $url,array $headers,?array $body):array { $this->sent=compact('method','url','headers','body');return [json_encode($this->reply),empty($this->reply['success'])?403:200,0,null]; }
}
$transport=new CredentialTransport($globalKey,$globalEmail);$transport->request('GET','/zones',null,['page'=>1]);
check(in_array('X-Auth-Email: '.$globalEmail,$transport->sent['headers'],true)&&in_array('X-Auth-Key: '.$globalKey,$transport->sent['headers'],true)&&!array_filter($transport->sent['headers'],fn($h)=>str_starts_with($h,'Authorization:')),'Global Key requests send both legacy headers without Bearer');
$tokenTransport=new CredentialTransport(str_repeat('t',40));$tokenTransport->request('GET','/zones');
check(in_array('Authorization: Bearer '.str_repeat('t',40),$tokenTransport->sent['headers'],true)&&!array_filter($tokenTransport->sent['headers'],fn($h)=>str_starts_with($h,'X-Auth-')),'Token requests preserve Bearer authentication');
$transport->reply=['success'=>false,'errors'=>[['message'=>$globalKey.' '.$globalEmail]]];
try{$transport->request('GET','/zones');throw new RuntimeException('Expected provider error');}catch(\Panel\Core\AppException $e){check(!str_contains($e->getMessage(),$globalKey)&&!str_contains($e->getMessage(),$globalEmail),'provider errors redact both Global Key and email');}
rejects(fn()=>new CredentialTransport("token\r\nInjected: yes"),422,'direct client rejects credential header injection');
rejects(fn()=>$transport->request('GET','/zones/../user'),500,'credential transport retains API path boundary');

$globalId=$configs->save('accounts',$globalInput,'test');
$stored=$db->one('SELECT * FROM accounts WHERE id=?',[$globalId]);
check($stored['auth_type']==='global_key'&&!str_contains($stored['token_encrypted'],$globalKey)&&!str_contains($stored['token_encrypted'],$globalEmail),'Global Key and email encrypted together in MySQL');
check($configs->authentication($globalId)===['auth_type'=>'global_key','email'=>$globalEmail],'admin authentication metadata returns email without key');
check(!str_contains(Validator::json($configs->list()),$globalEmail)&&!str_contains(Validator::json($configs->list()),$globalKey),'shared configuration omits Global Key and email');
$db->run('UPDATE accounts SET checked_at=UTC_TIMESTAMP() WHERE id=?',[$globalId]);
$configs->update('accounts',$globalId,['auth_type'=>'global_key','email'=>$globalEmail,'global_key'=>''],'test');
check($db->value('SELECT token_encrypted FROM accounts WHERE id=?',[$globalId])===$stored['token_encrypted']&&$db->value('SELECT checked_at FROM accounts WHERE id=?',[$globalId])!==null,'unchanged Global Key edits preserve ciphertext and connection timestamp');
$configs->update('accounts',$globalId,['email'=>'changed@example.test'],'test');
$rotated=CloudflareCredential::stored('global_key',$crypto->decrypt($db->value('SELECT token_encrypted FROM accounts WHERE id=?',[$globalId])));
check($rotated['secret']===$globalKey&&$rotated['email']==='changed@example.test'&&$db->value('SELECT checked_at FROM accounts WHERE id=?',[$globalId])===null,'email edits retain key and reset verification timestamp');
check((new Cloud($db,$crypto))->client($globalId) instanceof CloudflareClient,'stored Global Key builds the real Cloudflare client');
rejects(fn()=>$configs->update('accounts',$globalId,['auth_type'=>'api_token'],'test'),422,'account edits reject credential-free authentication mode switches');
$configs->update('accounts',$globalId,['auth_type'=>'api_token','token'=>str_repeat('t',40)],'test');
check($configs->authentication($globalId)===['auth_type'=>'api_token','email'=>null]&&$crypto->decrypt($db->value('SELECT token_encrypted FROM accounts WHERE id=?',[$globalId]))===str_repeat('t',40),'switch to Token erases old Global Key payload');
$configs->update('accounts',$globalId,['auth_type'=>'global_key','email'=>$globalEmail,'global_key'=>$globalKey],'test');
$audit=Validator::json($db->all('SELECT * FROM audit_logs'));
check(!str_contains($audit,$globalKey)&&!str_contains($audit,$globalEmail)&&!str_contains($audit,'changed@example.test'),'credential mutations never audit key or account email');
$configs->delete('accounts',$globalId,'test');
check($db->value('SELECT token_encrypted FROM accounts WHERE id=?',[$globalId])==='','deleting Global Key account erases both encrypted credentials');
rejects(fn()=>$configs->authentication($globalId),404,'deleted credential metadata cannot be read');

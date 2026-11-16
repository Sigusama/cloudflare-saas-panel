<?php
// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);
namespace Panel\Services;

use Panel\Core\{AppException,Database,Validator};
use Panel\Cloudflare\CloudflareClient;

final class ZoneManagement
{
    public function __construct(private Database $db,private Cloud $cloud) {}

    private function identifier(mixed $value):string
    {
        if(!is_string($value)||!preg_match('/^[a-f0-9]{32}$/iD',trim($value))){throw new AppException('Cloudflare Account ID 必须是 32 位十六进制字符串');}
        return strtolower(trim($value));
    }

    private function demo():bool { return $this->db->value("SELECT value FROM settings WHERE name='preview_mode'")==='1'; }

    public function accounts(int $credential):array
    {
        $client=$this->cloud->client($credential);
        if($this->demo()){return ['items'=>[],'manual'=>true,'demo'=>true];}
        try{$rows=$client->pages('/accounts');}
        catch(AppException $e){
            if($e->status>=500){throw $e;}
            // Listing accounts is optional; zone-scoped tokens may still expose account IDs.
            try{$rows=array_column($client->pages('/zones'),'account');}
            catch(AppException $fallback){if($fallback->status>=500){throw $fallback;}$rows=[];}
        }
        $items=[];
        foreach($rows as $row){if(is_array($row)&&is_string($row['id']??null)&&preg_match('/^[a-f0-9]{32}$/iD',$row['id'])){$id=strtolower($row['id']);$items[$id]=['id'=>$id,'name'=>(string)($row['name']??$id)];}}
        return ['items'=>array_values($items),'manual'=>true,'demo'=>false];
    }

    private function find(CloudflareClient $client,string $name,string $account):?array
    {
        $matches=[];
        foreach($client->pages('/zones',['name'=>$name,'account.id'=>$account]) as $zone){
            if(strtolower(rtrim((string)($zone['name']??''),'.'))===$name&&strtolower((string)($zone['account']['id']??''))===$account){$matches[]=$zone;}
        }
        if(count($matches)>1){throw new AppException('Cloudflare 返回多个同名主域名，请在 Cloudflare 核对后重试',409);}
        return $matches[0]??null;
    }

    public function create(int $credential,array $input,string $actor):array
    {
        $name=Validator::hostname($input['name']??null);
        $account=$this->identifier($input['cf_account_id']??null);
        $client=$this->cloud->client($credential);
        if($this->demo()){throw new AppException('演示模式不能在 Cloudflare 创建主域名',409,'demo_read_only');}
        $zone=$this->find($client,$name,$account);$created=false;$reconciled=false;
        if(!$zone){
            try{$zone=$client->request('POST','/zones',['account'=>['id'=>$account],'name'=>$name,'type'=>'full'])['result']??[];$created=true;}
            catch(AppException $e){
                // Creation may have succeeded even when its response was lost. Never blindly POST twice.
                try{$zone=$this->find($client,$name,$account);}catch(AppException){$zone=null;}
                if(!$zone){
                    if($e->status>=500){throw new AppException('创建结果尚未确认，请稍后用相同账号和域名重新提交以核对',503,'zone_creation_unknown');}
                    throw $e;
                }
                $reconciled=true;
            }
        }
        $result=$this->store($credential,$zone,$name,$account);
        (new Audit($this->db))->write($actor,$created?'zone.created':'zone.reused',($created?'在 Cloudflare 添加主域名：':'复用 Cloudflare 已有主域名：').$name);
        return $result+['created'=>$created,'reconciled'=>$reconciled];
    }

    public function details(int $id):array
    {
        $local=(new ZoneRecords($this->db))->zone($id);
        $client=$this->cloud->client((int)$local['account_id']);
        if($this->demo()){throw new AppException('演示模式仅展示本地主域名，不查询 Cloudflare NS',409,'demo_read_only');}
        $zone=$client->request('GET','/zones/'.$local['cf_id'])['result']??[];
        if(($zone['id']??null)!==$local['cf_id']){throw new AppException('Cloudflare 主域名信息与本地记录不一致',503);}
        return $this->store((int)$local['account_id'],$zone,$local['name']);
    }

    private function store(int $credential,array $zone,string $name,?string $account=null):array
    {
        $id=$zone['id']??null;$owner=$zone['account']['id']??null;$status=$zone['status']??null;
        if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/iD',$id)||!is_string($owner)||!preg_match('/^[a-f0-9]{32}$/iD',$owner)||($account!==null&&strtolower($owner)!==$account)||!is_string($zone['name']??null)||strtolower(rtrim($zone['name'],'.'))!==$name||!is_string($status)||!in_array($status,['initializing','pending','active','moved','deactivated'],true)){
            throw new AppException('Cloudflare 主域名信息与请求不一致，请同步账号后核对',503,'zone_response_invalid');
        }
        $servers=$zone['name_servers']??[];
        if(!is_array($servers)||count($servers)>20){throw new AppException('Cloudflare 返回的 NS 信息不正确',503);}
        try{$servers=array_values(array_unique(array_map(Validator::hostname(...),$servers)));}
        catch(AppException){throw new AppException('Cloudflare 返回的 NS 信息不正确',503);}
        $existing=$this->db->one('SELECT name FROM zones WHERE account_id=? AND cf_id=?',[$credential,$id]);
        if($existing&&$existing['name']!==$name){throw new AppException('Cloudflare 主域名信息与本地记录不一致',503);}
        $this->db->run('INSERT INTO zones(account_id,cf_id,name,status) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name),status=VALUES(status)',[$credential,$id,$name,$status]);
        $localId=(int)$this->db->value('SELECT id FROM zones WHERE account_id=? AND cf_id=?',[$credential,$id]);
        return ['id'=>$localId,'cf_id'=>$id,'account_id'=>$credential,'cf_account_id'=>strtolower($owner),'name'=>$name,'status'=>$status,'type'=>(string)($zone['type']??'full'),'name_servers'=>$servers];
    }
}

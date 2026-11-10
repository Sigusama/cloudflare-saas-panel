<?php
// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);
namespace Panel\Services;

use Panel\Core\{Database,Validator,AppException};

final class SaasDiscovery
{
    public function __construct(private Database $db,private Cloud $cloud) {}

    public function read(int $account,string $zoneId):array
    {
        $zone=$this->db->one("SELECT z.* FROM zones z JOIN accounts a ON a.id=z.account_id WHERE z.account_id=? AND z.cf_id=? AND a.active=1 AND a.deleted_at IS NULL AND z.status='active'",[$account,$zoneId]);
        if(!$zone){throw new AppException('请选择该账号中已同步且启用的接入站点',422);}
        if($this->db->value("SELECT value FROM settings WHERE name='preview_mode'")==='1'){
            $profile=$this->db->one('SELECT * FROM profiles WHERE account_id=? AND zone_id=? AND active=1 AND deleted_at IS NULL',[$account,$zoneId]);
            if(!$profile){throw new AppException('演示模式不连接 Cloudflare，请选择已有演示 SaaS 的站点');}
            return ['name'=>$profile['name'],'zone_id'=>$zoneId,'zone_name'=>$zone['name'],'fallback_origin'=>$profile['fallback_origin'],'fallback_status'=>'demo','cname_candidates'=>[$profile['cname_target']],'cname_target'=>$profile['cname_target'],'source'=>'demo'];
        }
        $client=$this->cloud->client($account);
        try{$fallback=$client->request('GET','/zones/'.$zoneId.'/custom_hostnames/fallback_origin')['result']??[];}
        catch(AppException $e){if($e->status===404){throw new AppException('此主域名未配置 SaaS 默认回源，普通 DNS 管理不受影响；请选择已配置 SaaS 的站点',422,'saas_not_configured');}throw $e;}
        if(empty($fallback['origin'])){throw new AppException('该站点尚未配置 SaaS 默认回源，请先在 Cloudflare 开通 SaaS 并设置默认回源，或选择已有配置的站点',422,'saas_not_configured');}
        $origin=Validator::hostname($fallback['origin']);
        if(!Validator::zoneMatches($origin,$zone['name'])){throw new AppException('Cloudflare 默认回源不属于此接入站点，请检查站点配置');}
        $records=$client->pages('/zones/'.$zoneId.'/dns_records',['type'=>'CNAME','proxied'=>'true']);
        $candidates=[];
        foreach($records as $record){
            if(($record['type']??'')!=='CNAME'||empty($record['proxied'])||strtolower(rtrim($record['content']??'','.'))!==$origin){continue;}
            try{$hostname=Validator::hostname($record['name']??'');}catch(AppException){continue;}
            if($hostname!==$origin&&Validator::zoneMatches($hostname,$zone['name'])){$candidates[]=$hostname;}
        }
        $candidates=array_values(array_unique($candidates));sort($candidates);
        return ['name'=>$zone['name'].' SaaS','zone_id'=>$zoneId,'zone_name'=>$zone['name'],'fallback_origin'=>$origin,'fallback_status'=>$fallback['status']??'unknown','cname_candidates'=>$candidates,'cname_target'=>count($candidates)===1?$candidates[0]:null,'source'=>'cloudflare'];
    }
}

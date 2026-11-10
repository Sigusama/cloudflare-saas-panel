<?php
// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);
namespace Panel\Services;

use Panel\Core\{Database,Validator,AppException};

final class DomainConfig
{
    public function __construct(private Database $db) {}
    public static function checkOptions(mixed $input,array $previous=[]):array
    {
        if (!is_array($input)||isset($input['path'])&&!is_string($input['path'])||isset($input['keyword'])&&!is_string($input['keyword'])) { throw new AppException('访问检查选项格式不正确'); }
        $path=(string)($input['path']??$previous['path']??'/');
        if (!str_starts_with($path,'/')||str_starts_with($path,'//')||preg_match('/[\x00-\x20\\\\#]/',$path)||strlen($path)>1024) { throw new AppException('检查路径必须是以 / 开头的站内路径，不含空白、片段或协议'); }
        $statuses=$input['statuses']??$previous['statuses']??[200,201,202,204,301,302,303,307,308];
        if (is_string($statuses)) { $statuses=preg_split('/[\s,]+/',trim($statuses),-1,PREG_SPLIT_NO_EMPTY); }
        if (!is_array($statuses)||count($statuses)<1||count($statuses)>50) { throw new AppException('请提供 1 至 50 个预期 HTTP 状态码'); }
        foreach ($statuses as $status) { if (!is_numeric($status)||(int)$status<100||(int)$status>599||(string)(int)$status!==(string)$status) { throw new AppException('HTTP 状态码不正确'); } }
        $keyword=(string)($input['keyword']??$previous['keyword']??'');
        if (mb_strlen($keyword)>512) { throw new AppException('响应关键字不超过 512 字'); }
        return ['path'=>$path,'statuses'=>array_values(array_unique(array_map('intval',$statuses))),'keyword'=>$keyword];
    }
    public function capture(array $domain,?int $entryId=null,?int $originId=null,array $check=[]):array
    {
        $profile=$this->db->one('SELECT * FROM profiles WHERE id=?',[$domain['profile_id']]);
        $entry=$this->db->one('SELECT * FROM entries WHERE id=?',[$entryId??$domain['entry_id']]);
        $origin=$this->db->one('SELECT * FROM origins WHERE id=?',[$originId??$domain['origin_id']]);
        if (!$profile||!$entry||!$origin||(int)$origin['profile_id']!==(int)$profile['id']) { throw new AppException('入口、回源或 SaaS 配置不匹配'); }
        $originFields=array_intersect_key($origin,array_flip(['id','name','profile_id','mode','hostname']));
        if (!empty($origin['source_ip'])) { $originFields['source_ip']=$origin['source_ip']; }
        if (!empty($origin['source_hostname'])) { $originFields['source_hostname']=$origin['source_hostname']; }
        if(!empty($origin['external_dns_json'])){$originFields['external_dns']=json_decode($origin['external_dns_json'],true,64,JSON_THROW_ON_ERROR);}
        return ['profile'=>array_intersect_key($profile,array_flip(['id','account_id','zone_id','zone_name','cname_target','fallback_origin'])),'entry'=>(new EntryAlias($this->db))->capture($entry),'origin'=>$originFields,'check'=>self::checkOptions($check)];
    }
    public function applied(array $domain):array
    {
        if (!empty($domain['applied_config'])) { return json_decode($domain['applied_config'],true,64,JSON_THROW_ON_ERROR); }
        $config=$this->capture($domain);
        $this->db->run('UPDATE domains SET applied_config=? WHERE id=? AND applied_config IS NULL',[Validator::json($config),$domain['id']]);
        return $config;
    }
    public function assertAvailable(array $config,int $dnsAccount):void
    {
        DnsMode::validateConfig($config);
        foreach (['profiles'=>$config['profile']['id'],'entries'=>$config['entry']['id'],'origins'=>$config['origin']['id']] as $table=>$id) {
            if (!(int)$this->db->value('SELECT COUNT(*) FROM '.$table.' WHERE id=? AND active=1 AND deleted_at IS NULL',[$id])) { throw new AppException('所选配置已停用或删除：'.$table); }
        }
        $accountIds=[$dnsAccount,(int)$config['profile']['account_id']];
        if(!empty($config['entry']['hostname'])){$accountIds[]=(int)$config['entry']['dns_account_id'];}
        foreach (array_unique($accountIds) as $id) {
            if (!(int)$this->db->value('SELECT COUNT(*) FROM accounts WHERE id=? AND active=1 AND deleted_at IS NULL',[$id])) { throw new AppException('所选 Cloudflare 账号已停用或删除'); }
        }
    }
    public static function equivalent(array $a,array $b):bool
    {
        $a['dns_mode']=DnsMode::of($a);$b['dns_mode']=DnsMode::of($b);
        unset($a['entry']['name'],$b['entry']['name'],$a['origin']['name'],$b['origin']['name']);
        unset($a['observed_dns'],$b['observed_dns']);
        unset($a['observed_address_records'],$b['observed_address_records'],$a['observed_dns_mode'],$b['observed_dns_mode']);
        return $a==$b;
    }
}

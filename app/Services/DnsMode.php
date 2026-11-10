<?php
// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);
namespace Panel\Services;

use Panel\Core\{Validator,AppException};
use Panel\Dns\DnsProvider;

final class DnsMode
{
    public static function validate(mixed $mode):string
    {
        if (!is_string($mode)||!in_array($mode,['dns_only','proxied','preferred'],true)) { throw new AppException('DNS 模式请选择 dns_only、proxied 或 preferred'); }
        return $mode;
    }
    public static function of(array $config):string { return self::validate($config['dns_mode']??'preferred'); }
    public static function preferred(array $config):bool { return self::of($config)==='preferred'; }
    public static function validateConfig(array $config):void
    {
        if(self::preferred($config)&&$config['entry']['kind']==='standard'&&empty($config['profile']['cname_target'])){throw new AppException('该站点仅按已有优选配置纳管，未设置标准接入 CNAME；请选择现有优选目标');}
        if (!self::preferred($config)) {
            if (empty($config['origin']['source_ip'])&&empty($config['origin']['source_hostname'])) { throw new AppException('关云或开启小黄云模式需填写源站 IP 或 CNAME；历史回源预设请使用优选模式'); }
            if(!empty($config['origin']['source_ip'])){Validator::publicIp($config['origin']['source_ip']);}else{Validator::hostname($config['origin']['source_hostname']);}
        }
    }
    public static function desired(array $domain,array $config):array
    {
        self::validateConfig($config);
        if (!self::preferred($config)) {
            if(!empty($config['observed_address_records'])){return array_map(static function($record)use($config):array{$out=array_intersect_key($record,array_flip(['name','type','content','ttl','proxied']));if(self::of($config)!==($config['observed_dns_mode']??null)){$out['proxied']=self::of($config)==='proxied';if($out['proxied']){$out['ttl']=1;}}return $out;},$config['observed_address_records']);}
            $ip=empty($config['origin']['source_ip'])?null:Validator::publicIp($config['origin']['source_ip']);$proxied=self::of($config)==='proxied';
            return [['name'=>$domain['hostname'],'type'=>$ip===null?'CNAME':(str_contains($ip,':')?'AAAA':'A'),'content'=>$ip??Validator::hostname($config['origin']['source_hostname']),'proxied'=>$proxied,'ttl'=>$proxied?1:60]];
        }
        $entry=$config['entry'];
        if($entry['kind']!=='standard'&&!empty($entry['hostname'])){if($entry['hostname']===$domain['hostname']){throw new AppException('业务域名不能 CNAME 到自身入口');}return [['name'=>$domain['hostname'],'type'=>'CNAME','content'=>$entry['hostname'],'proxied'=>false,'ttl'=>60]];}
        $targets=$entry['kind']==='standard'?[$config['profile']['cname_target']]:json_decode($entry['targets'],true);
        return array_map(static fn($target):array=>['name'=>$domain['hostname'],'type'=>$entry['kind']==='ip'?(str_contains($target,':')?'AAAA':'A'):'CNAME','content'=>$target,'proxied'=>false,'ttl'=>60],$targets);
    }
    public static function verify(array $domain,array $config,DnsProvider $dns):array
    {
        $expected=self::desired($domain,$config);
        $records=array_values(array_filter($dns->records($domain['dns_zone_id'],$domain['hostname']),static fn($r):bool=>in_array($r['type'],['A','AAAA','CNAME'],true)));
        if (count($records)!==count($expected)) { throw new AppException('DNS 记录尚未与所选模式一致，请稍后重查',503); }
        foreach ($expected as $want) { if (!array_filter($records,static fn($r):bool=>DnsChanges::same($r,$want))) { throw new AppException('DNS 内容或小黄云状态未生效，请检查 Cloudflare 账号能力',503); } }
        return ['success'=>true,'dns_mode'=>self::of($config),'source'=>'Cloudflare DNS API','checked_at'=>gmdate('c'),'records'=>$expected];
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);
namespace Panel\Services;

use Panel\Core\{Validator, AppException};

class Diagnostics
{
    public function check(string $hostname,array $options=[]): array
    {
        $hostname=Validator::hostname($hostname);
        $forcedIps=$options['_connect_ips']??null;$requireAll=!empty($options['_require_all']);
        $connectHostname=Validator::hostname($options['_connect_hostname']??$hostname);
        $options=DomainConfig::checkOptions($options);
        $ips=[];
        if ($forcedIps!==null) { $ips=array_map(Validator::publicIp(...),$forcedIps); }
        else {
            foreach (dns_get_record($connectHostname,DNS_A|DNS_AAAA) ?: [] as $record) {
                $ip=$record['ip']??$record['ipv6']??null;
                if ($ip!==null) { $ips[]=Validator::publicIp($ip); }
            }
        }
        if ($ips===[]) { throw new AppException('业务域名尚未解析到可用公网地址',503,'dns_pending'); }
        $last=[];$results=[];
        foreach (array_slice(array_unique($ips),0,$requireAll?10:4) as $ip) {
            $curl=curl_init('https://'.$hostname.$options['path']);
            $bytes=0;$body='';
            curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>false,CURLOPT_CONNECTTIMEOUT=>6,CURLOPT_TIMEOUT=>12,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROXY=>'',CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_RESOLVE=>[$hostname.':443:'.(str_contains($ip,':')?'['.$ip.']':$ip)],CURLOPT_WRITEFUNCTION=>static function($handle,string $data) use (&$bytes,&$body):int { $bytes+=strlen($data);if ($bytes>1048576) { return 0; }$body.=$data;return strlen($data); },CURLOPT_USERAGENT=>'Cloudflare-SaaS-Panel/0.1']);
            $ok=curl_exec($curl);
            $info=curl_getinfo($curl);
            $error=curl_errno($curl);
            curl_close($curl);
            $last=['hostname'=>$hostname,'path'=>$options['path'],'ip'=>$info['primary_ip']??$ip,'http_status'=>$info['http_code']??0,'connect_ms'=>round(($info['connect_time']??0)*1000,1),'tls_ms'=>round(($info['appconnect_time']??0)*1000,1),'ttfb_ms'=>round(($info['starttransfer_time']??0)*1000,1),'checked_at'=>gmdate('c'),'source'=>'面板服务器','content_assertion'=>$options['keyword']!=='','keyword_matched'=>$options['keyword']===''?null:str_contains($body,$options['keyword'])];
            $passed=$ok!==false&&!$error&&self::matchesResponse((int)$last['http_status'],$body,$options);
            $results[]=$last+['success'=>$passed];
            if ($passed&&!$requireAll) { return $last+['success'=>true,'results'=>$results,'connect_hostname'=>$connectHostname]; }
        }
        if ($requireAll&&$results!==[]&&!array_filter($results,static fn($r):bool=>!$r['success'])) { return $last+['success'=>true,'results'=>$results,'all_selected_ips_checked'=>true]; }
        return $last+['success'=>false,'results'=>$results,'error'=>'所选入口的 HTTPS 证书、连接、预期 HTTP 状态或响应关键字检查未通过'];
    }
    public static function forConfig(array $config):array
    {
        $options=$config['check'];
        if (!DnsMode::preferred($config)) {
            if (DnsMode::of($config)==='dns_only') { if(!empty($config['origin']['source_ip'])){$options['_connect_ips']=[$config['origin']['source_ip']];}else{$options['_connect_hostname']=$config['origin']['source_hostname'];}$options['_require_all']=true; }
            return $options;
        }
        if ($config['entry']['kind']==='ip') { $options['_connect_ips']=json_decode($config['entry']['targets'],true);$options['_require_all']=true; }
        else { $options['_connect_hostname']=$config['entry']['hostname']??($config['entry']['kind']==='standard'?$config['profile']['cname_target']:(json_decode($config['entry']['targets'],true)[0])); }
        return $options;
    }
    public static function matchesResponse(int $status,string $body,array $options):bool
    {
        $options=DomainConfig::checkOptions($options);
        return in_array($status,$options['statuses'],true)&&($options['keyword']===''||str_contains($body,$options['keyword']));
    }
}

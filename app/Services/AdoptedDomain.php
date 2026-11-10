<?php
// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);
namespace Panel\Services;

use Panel\Core\{Database,Validator,AppException};

final class AdoptedDomain
{
    public function __construct(private Database $db,private Cloud $cloud,private Diagnostics $diagnostics) {}
    public function inspect(array $domain,array $job,array $config):void
    {
        $profile=$config['profile'];$remote=$this->cloud->client((int)$profile['account_id'])->request('GET','/zones/'.$profile['zone_id'].'/custom_hostnames/'.$domain['cf_hostname_id'])['result'];
        $normalized=SaasInventory::hostname($remote);$previous=json_decode($domain['remote_json']??'[]',true);
        if($normalized['hostname']!==$domain['hostname']||$normalized['id']!==$domain['cf_hostname_id']||$normalized['custom_origin_server']!==($config['origin']['mode']==='custom'?$config['origin']['hostname']:'')||$normalized['custom_origin_sni']!==($previous['custom_origin_sni']??'')){throw new AppException('已有 SaaS 主机名或回源被外部修改，请重新核对',409);}
        $this->db->run('UPDATE domains SET remote_json=?,hostname_status=?,ssl_status=? WHERE id=?',[Validator::json($normalized),$normalized['status'],$normalized['ssl']['status'],$domain['id']]);
        $fallback=$this->cloud->client((int)$profile['account_id'])->request('GET','/zones/'.$profile['zone_id'].'/custom_hostnames/fallback_origin')['result'];
        if(($fallback['origin']??'')!==$profile['fallback_origin']||($fallback['status']??'')!=='active'){throw new AppException('已有 SaaS 默认回源尚未就绪或已变化',409);}
        foreach($config['observed_dns']??[] as $baseline){ObservedDns::assertSame($baseline['records'],$this->cloud->dns((int)$baseline['account_id'])->records($baseline['zone_id'],$baseline['hostname']),$baseline['hostname']);}
        ObservedDns::verifySource($config['origin'],$this->cloud);
        DnsMode::verify($domain,$config,$this->cloud->dns((int)$domain['dns_account_id']));
        if($normalized['status']!=='active'||$normalized['ssl']['status']!=='active'){throw new AppException('已纳管，CF 主机名或证书仍在等待验证；保留原有解析，请在验证就绪后重试检查',422);}
        $result=$this->diagnostics->check($domain['hostname'],Diagnostics::forConfig($config));$this->db->run('UPDATE domains SET diagnostic_json=? WHERE id=?',[Validator::json($result),$domain['id']]);
        if(empty($result['success'])){throw new AppException($result['error']??'已有域名的 HTTPS 检查未通过',503);}
        $this->db->run("UPDATE domains SET status='active',last_error=NULL,next_check_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 MINUTE) WHERE id=?",[$domain['id']]);
        $this->db->run("UPDATE jobs SET status='completed',last_error=NULL WHERE id=?",[$job['id']]);
        (new Audit($this->db))->write('worker','inventory.inspected','已有 SaaS / DNS / 回源与实际 HTTPS 检查通过，云端资源归属保持为外部',(int)$domain['id'],(int)$job['id']);
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);
namespace Panel\Services;

use Panel\Core\{Database,Validator,AppException};

/** SaaS activation when a previously ordinary DNS record enters preferred mode. */
final class SaasProvision
{
    public function __construct(private Database $db,private Cloud $cloud) {}
    public function hostname(array $domain,array $job,array $config):array
    {
        $profile=$config['profile'];$origin=$config['origin'];$client=$this->cloud->client((int)$profile['account_id']);
        if (!$domain['cf_hostname_id']) {
            $matches=$client->pages('/zones/'.$profile['zone_id'].'/custom_hostnames',['hostname'=>$domain['hostname']]);
            $matches=array_values(array_filter($matches,static fn($r):bool=>strtolower($r['hostname'])===$domain['hostname']));
            $remote=$matches[0]??null;$created=false;
            if (!$remote) {
                $context=json_decode($job['context_json']??'{}',true);$context['creation_pending']=true;
                $this->db->run('UPDATE jobs SET context_json=? WHERE id=?',[Validator::json($context),$job['id']]);
                $body=['hostname'=>$domain['hostname'],'ssl'=>['method'=>'txt','type'=>'dv','settings'=>['min_tls_version'=>'1.2']]];
                if ($origin['mode']==='custom') { $body['custom_origin_server']=$origin['hostname']; }
                $remote=$client->request('POST','/zones/'.$profile['zone_id'].'/custom_hostnames',$body)['result'];$created=true;
            } elseif (($remote['custom_origin_server']??'')!==($origin['mode']==='custom'?$origin['hostname']:'')) { throw new AppException('已有 Custom Hostname 的回源不同，停止接管',409); }
            $this->db->run('UPDATE domains SET cf_hostname_id=?,hostname_created=? WHERE id=?',[$remote['id'],$created?1:0,$domain['id']]);
            $domain['cf_hostname_id']=$remote['id'];$domain['hostname_created']=$created?1:0;
            (new Audit($this->db))->write('worker','mode.hostname_ready',$created?'为优选模式创建 SaaS 主机名':'复用匹配的 SaaS 主机名',(int)$domain['id'],(int)$job['id']);
        }
        return $domain;
    }
    public function verification(array $domain,array $job,array $remote):void
    {
        $records=[];
        if (($remote['status']??'')!=='active'&&!empty($remote['ownership_verification']['name'])) {
            $r=$remote['ownership_verification'];$records[]=['name'=>$r['name'],'value'=>$r['value'],'purpose'=>'ownership'];
        }
        if (($remote['ssl']['status']??'')!=='active') {
            foreach ($remote['ssl']['validation_records']??[] as $r) { if (!empty($r['txt_name'])&&!empty($r['txt_value'])) { $records[]=['name'=>$r['txt_name'],'value'=>$r['txt_value'],'purpose'=>'certificate']; } }
        }
        $dns=$this->cloud->dns((int)$domain['dns_account_id']);$changes=new DnsChanges($this->db,new Audit($this->db));
        $zones=$this->db->all('SELECT * FROM zones WHERE account_id=? ORDER BY CHAR_LENGTH(name) DESC',[$domain['dns_account_id']]);
        foreach ($records as $r) {
            $zone=null;
            foreach ($zones as $candidate) { if (Validator::zoneMatches(strtolower(rtrim($r['name'],'.')),$candidate['name'])) { $zone=$candidate;break; } }
            if (!$zone) { throw new AppException('没有权限管理验证名称：'.$r['name']); }
            $changes->ensureTxt($domain,$job,$dns,$zone['cf_id'],strtolower(rtrim($r['name'],'.')),$r['value'],$r['purpose']);
        }
    }
}

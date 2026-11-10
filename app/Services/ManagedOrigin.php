<?php
// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);
namespace Panel\Services;

use Panel\Core\{Database,Validator,AppException};

/** Immutable, per-business-hostname source aliases. Visitor DNS is handled separately. */
final class ManagedOrigin
{
    public function __construct(private Database $db) {}

    public function select(array $profile,string $hostname,mixed $input): int
    {
        $ip=Validator::publicIp($input);
        return $this->selectSource($profile,$hostname,$ip,null);
    }
    public function selectHostname(array $profile,string $hostname,mixed $input):int
    {
        if(!is_string($input)){throw new AppException('CNAME 回源必须是域名');}
        $source=Validator::hostname($input);
        if(filter_var($source,FILTER_VALIDATE_IP)||$source===$hostname){throw new AppException('CNAME 回源需填写其他源站域名，不能填 IP 或业务域名自身');}
        return $this->selectSource($profile,$hostname,null,$source);
    }
    private function selectSource(array $profile,string $hostname,?string $ip,?string $source):int
    {
        $value=$ip??$source;
        $alias=Validator::hostname('src-'.substr(hash('sha256',$profile['id'].'|'.$hostname.'|'.($ip??('cname|'.$source))),0,24).'.'.$profile['zone_name']);
        if($source===$alias){throw new AppException('CNAME 回源不能指向自身代理别名');}
        $old=$this->db->one('SELECT id,source_ip,source_hostname FROM origins WHERE profile_id=? AND hostname=? AND deleted_at IS NULL',[$profile['id'],$alias]);
        if ($old) {
            if ($old['source_ip']!==$ip||$old['source_hostname']!==$source) { throw new AppException('自动回源名称冲突，请检查回源配置',409); }
            return (int)$old['id'];
        }
        return $this->db->insert("INSERT INTO origins(name,profile_id,mode,hostname,source_ip,source_hostname) VALUES(?,?,'custom',?,?,?)",[mb_substr($hostname.' → '.$value,0,100),$profile['id'],$alias,$ip,$source]);
    }

    public function ensure(array $domain,array $job,array $config,Cloud $cloud): void
    {
        $origin=$config['origin'];
        if(!empty($origin['external_dns'])){ObservedDns::verifySource($origin,$cloud);return;}
        if (empty($origin['source_ip'])&&empty($origin['source_hostname'])) { return; }
        $profile=$config['profile'];
        $ip=empty($origin['source_ip'])?null:Validator::publicIp($origin['source_ip']);
        $source=$ip===null?Validator::hostname($origin['source_hostname']):null;
        $dns=$cloud->dns((int)$profile['account_id']);
        $row=$this->db->one('SELECT * FROM managed_sources WHERE domain_id=? AND hostname=?',[$domain['id'],$origin['hostname']]);
        if (!$row) {
            $id=$this->db->insert('INSERT INTO managed_sources(domain_id,account_id,zone_id,hostname,source_ip,source_hostname) VALUES(?,?,?,?,?,?)',[$domain['id'],$profile['account_id'],$profile['zone_id'],$origin['hostname'],$ip,$source]);
            $row=$this->db->one('SELECT * FROM managed_sources WHERE id=?',[$id]);
        }
        $marker='cf-saas-panel:source:'.$domain['id'].':'.$row['id'];
        $desired=['name'=>$origin['hostname'],'type'=>$ip===null?'CNAME':(str_contains($ip,':')?'AAAA':'A'),'content'=>$ip??$source,'proxied'=>true,'ttl'=>1,'comment'=>$marker];
        $records=$dns->records($profile['zone_id'],$origin['hostname']);
        $found=null;
        foreach ($records as $record) {
            if (!DnsChanges::same($record,$desired)||($record['comment']??'')!==$marker) { throw new AppException('自动回源记录已存在或被外部修改，停止覆盖：'.$origin['hostname'],409); }
            $found=$record;
        }
        // Persist intent before the request; a lost response can be reconciled by the unique marker.
        if (!$found) {
            if ($row['state']==='applied') { throw new AppException('自动回源记录被外部删除，请检查后重试',409); }
            $this->db->run("UPDATE managed_sources SET state='planned',after_json=? WHERE id=?",[Validator::json($desired),$row['id']]);
            $found=$dns->create($profile['zone_id'],$desired);
            (new Audit($this->db))->write('worker','source.created','自动创建代理回源：'.$origin['hostname'].' → '.($ip??$source),(int)$domain['id'],(int)$job['id']);
        }
        $this->db->run("UPDATE managed_sources SET state='applied',record_id=?,after_json=? WHERE id=?",[$found['id'],Validator::json($found),$row['id']]);
    }

    /** Only call after the remote custom hostname has been deleted (or confirmed absent). */
    public function cleanup(array $domain,array $job,Cloud $cloud): void
    {
        foreach ($this->db->all("SELECT * FROM managed_sources WHERE domain_id=? AND state<>'deleted'",[$domain['id']]) as $row) {
            $dns=$cloud->dns((int)$row['account_id']);
            $expected=json_decode($row['after_json']??'null',true);
            foreach ($dns->records($row['zone_id'],$row['hostname']) as $record) {
                if (!$expected||!DnsChanges::same($record,$expected)||($record['comment']??'')!==($expected['comment']??'')) { throw new AppException('清理回源发现外部修改，停止删除：'.$row['hostname'],409); }
                $dns->delete($row['zone_id'],$record['id']);
            }
            $this->db->run("UPDATE managed_sources SET state='deleted' WHERE id=?",[$row['id']]);
            (new Audit($this->db))->write('worker','source.deleted','清理自动回源：'.$row['hostname'],(int)$domain['id'],(int)$job['id']);
        }
    }
}

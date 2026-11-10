<?php
// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);
namespace Panel\Services;

use Panel\Core\{Database,Validator,AppException};

final class ZoneRecords
{
    public function __construct(private Database $db) {}

    public function zones(): array
    {
        return ['items'=>$this->db->all("SELECT z.*,a.name AS account_name,a.active AS account_active,
            COALESCE((SELECT JSON_LENGTH(s.records_json) FROM zone_dns_snapshots s WHERE s.zone_ref=z.id),(SELECT COUNT(*) FROM domains d WHERE d.dns_account_id=z.account_id AND d.dns_zone_id=z.cf_id AND d.deleted_at IS NULL)) AS record_count,
            (SELECT COUNT(*) FROM domains d WHERE d.dns_account_id=z.account_id AND d.dns_zone_id=z.cf_id AND d.deleted_at IS NULL) AS managed_count
            FROM zones z JOIN accounts a ON a.id=z.account_id WHERE a.deleted_at IS NULL ORDER BY z.name,a.id")];
    }

    public function zone(int $id,bool $write=false): array
    {
        $zone=$this->db->one('SELECT z.*,a.active AS account_active FROM zones z JOIN accounts a ON a.id=z.account_id WHERE z.id=? AND a.deleted_at IS NULL',[$id]);
        if (!$zone) { throw new AppException('主域名不存在，请先同步账号 Zone',404); }
        if ($write&&($zone['status']!=='active'||!$zone['account_active'])) { throw new AppException('该主域名或 DNS 账号尚未启用'); }
        return $zone;
    }

    public function records(int $zoneId,int $page,int $per,string $search='',bool $includeExisting=false): array
    {
        $zone=$this->zone($zoneId);
        $where='d.deleted_at IS NULL AND d.dns_account_id=? AND d.dns_zone_id=?';$params=[$zone['account_id'],$zone['cf_id']];
        $snapshot=(new ZoneDns($this->db))->snapshot($zoneId);$observed=[];$keys=null;
        if($includeExisting){
            $keys=[];$managed=[];
            foreach($this->db->all('SELECT d.id,d.hostname FROM domains d WHERE '.$where.' ORDER BY d.id DESC',$params) as $row){$managed[$row['hostname']]=true;$keys[]=['managed'=>true,'id'=>$row['id'],'hostname'=>$row['hostname']];}
            $operations=array_column($this->db->all("SELECT o.id,o.record_id,o.status,o.last_error,o.domain_id FROM dns_operations o JOIN (SELECT record_id,MAX(id) AS id FROM dns_operations WHERE zone_ref=? GROUP BY record_id) latest ON latest.id=o.id WHERE o.status<>'completed'",[$zoneId]),null,'record_id');
            $raw=[];
            foreach($snapshot['records'] as $record){
                if(isset($managed[$record['name']])&&in_array($record['type'],['A','AAAA','CNAME'],true)){$observed[$record['name']][]=$record;continue;}
                $raw[]=['managed'=>false,'id'=>'cf:'.$record['id'],'hostname'=>$record['name'],'record_type'=>$record['type'],'record'=>$record,'expected_fingerprint'=>ObservedDns::fingerprint([$record]),'operation'=>$operations[$record['id']]??null,'dns_records'=>[$record],'dns_mode'=>!empty($record['proxied'])?'proxied':'dns_only'];
            }
            usort($raw,static fn($a,$b)=>[$a['hostname'],$a['record_type'],$a['id']]<=>[$b['hostname'],$b['record_type'],$b['id']]);$keys=array_merge($keys,$raw);
            if($search!==''){$needle=mb_strtolower(mb_substr($search,0,253));$keys=array_values(array_filter($keys,static function($row)use($needle,$observed):bool{$rr=$row['managed']?($observed[$row['hostname']]??[]):[$row['record']];return str_contains(mb_strtolower($row['hostname'].' '.implode(' ',array_column($rr,'type')).' '.implode(' ',array_column($rr,'content'))),$needle);}));}
            $total=count($keys);$keys=array_slice($keys,($page-1)*$per,$per);$ids=array_column(array_filter($keys,fn($row)=>$row['managed']),'id');
            $items=$ids?$this->db->all('SELECT d.*,p.name AS profile_name,e.name AS entry_name,o.name AS origin_name,j.stage AS job_stage FROM domains d JOIN profiles p ON p.id=d.profile_id JOIN entries e ON e.id=d.entry_id JOIN origins o ON o.id=d.origin_id LEFT JOIN jobs j ON j.id=(SELECT MAX(jj.id) FROM jobs jj WHERE jj.domain_id=d.id) WHERE '.$where.' AND d.id IN ('.implode(',',array_fill(0,count($ids),'?')).')',[...$params,...$ids]):[];
        }else{
        if ($search!=='') { $where.=' AND d.hostname LIKE ?';$params[]='%'.mb_substr($search,0,253).'%'; }
        $items=$this->db->all('SELECT d.*,p.name AS profile_name,e.name AS entry_name,o.name AS origin_name,j.stage AS job_stage FROM domains d JOIN profiles p ON p.id=d.profile_id JOIN entries e ON e.id=d.entry_id JOIN origins o ON o.id=d.origin_id LEFT JOIN jobs j ON j.id=(SELECT MAX(jj.id) FROM jobs jj WHERE jj.domain_id=d.id) WHERE '.$where.' ORDER BY d.id DESC LIMIT '.$per.' OFFSET '.(($page-1)*$per),$params);
        $total=(int)$this->db->value('SELECT COUNT(*) FROM domains d WHERE '.$where,$params);
        }
        foreach ($items as &$item) {
            $item['managed']=true;
            $applied=(new DomainConfig($this->db))->applied($item);$item['applied_config']=$applied;
            $item['source']=$applied['origin']['source_ip']??$applied['origin']['source_hostname']??$applied['origin']['hostname'];
            $item['record_type']=isset($applied['origin']['source_ip'])?(str_contains($item['source'],':')?'AAAA':'A'):(isset($applied['origin']['source_hostname'])?'CNAME':'预设');
            $item['entry_name']=$applied['entry']['name'];$item['origin_name']=$applied['origin']['name'];
            $item['dns_records']=DomainChange::desired($item,$applied);
            $item['dns_mode']=DnsMode::of($applied);
            if($includeExisting&&$snapshot['loaded']){$item['observed_dns_records']=$observed[$item['hostname']]??[];}
        }unset($item);
        if($keys!==null){$byId=array_column($items,null,'id');$items=array_values(array_filter(array_map(static fn($row)=>$row['managed']?($byId[$row['id']]??null):$row,$keys)));}
        return ['zone'=>$zone,'items'=>$items,'page'=>$page,'per_page'=>$per,'total'=>$total,'dns_snapshot'=>['loaded'=>$snapshot['loaded'],'synced_at'=>$snapshot['synced_at'],'record_count'=>count($snapshot['records'])]];
    }

    public function create(int $zoneId,array $input,string $actor):array
    {
        $zone=$this->zone($zoneId,true);
        if (array_key_exists('hostname',$input)) { $hostname=Validator::hostname($input['hostname']); }
        else {
            if (!is_string($input['name']??null)) { throw new AppException('请填写记录名称，例如 www 或 @'); }
            $name=strtolower(trim($input['name']));
            $hostname=$name==='@'?$zone['name']:Validator::hostname(Validator::zoneMatches(rtrim($name,'.'),$zone['name'])?rtrim($name,'.'):$name.'.'.$zone['name']);
        }
        if (!Validator::zoneMatches($hostname,$zone['name'])) { throw new AppException('记录必须属于所选主域名'); }
        $type=$input['type']??'A';
        if (!in_array($type,['A','AAAA','CNAME','preset'],true)) { throw new AppException('回源类型请选择 A、AAAA、CNAME 或 preset'); }
        $payload=$input;
        $payload['hostnames']=[$hostname];$payload['dns_account_id']=(int)$zone['account_id'];$payload['dns_zone_id']=$zone['cf_id'];
        if(array_key_exists('source_ip',$input)&&array_key_exists('source_hostname',$input)){throw new AppException('回源 IP 和 CNAME 域名请选择一种');}
        if ($type==='preset') { unset($payload['source_ip'],$payload['source_hostname']); }
        elseif($type==='CNAME') { if(isset($input['source_ip'])){throw new AppException('CNAME 回源请填写域名');}$payload['source_hostname']=$input['source_hostname']??''; }
        else {
            if(isset($input['source_hostname'])){throw new AppException('IP 回源请填写 source_ip');}
            $ip=Validator::publicIp($input['source_ip']??'');
            if (($type==='AAAA')!==str_contains($ip,':')) { throw new AppException('记录类型与源站 IP 地址不匹配'); }
            $payload['source_ip']=$ip;
        }
        $result=(new DomainService($this->db))->create($payload,$actor);
        if (isset($result['items'][0]['error'])) { throw new AppException($result['items'][0]['error'],409); }
        return $result;
    }
}

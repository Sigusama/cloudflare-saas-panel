<?php
// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);
namespace Panel\Services;

use Panel\Core\{Database, Validator, AppException};
use Panel\Dns\DnsProvider;

final class DnsChanges
{
    public function __construct(private Database $db, private Audit $audit) {}

    public static function payload(array $record): array
    {
        return array_intersect_key($record, array_flip(['name','type','content','ttl','proxied','priority','comment','tags','settings','data']));
    }
    public static function same(array $a, array $b): bool
    {
        if(in_array(strtoupper($a['type']),['A','AAAA'],true)){$address=@inet_pton($a['content']);$contentSame=$address!==false&&$address===@inet_pton($b['content']);}
        else{$contentSame=$a['type']==='TXT'?trim($a['content'],'"')===trim($b['content'],'"'):strtolower(rtrim($a['content'],'.'))===strtolower(rtrim($b['content'],'.'));}
        return strtoupper($a['type']) === strtoupper($b['type'])
            && strtolower(rtrim($a['name'], '.')) === strtolower(rtrim($b['name'], '.'))
            && $contentSame
            && ($a['type']==='TXT' || (bool)($a['proxied'] ?? false) === (bool)($b['proxied'] ?? false));
    }

    public function reconcile(array $domain, array $job, DnsProvider $dns, bool $execute=true,?int $onlyJob=null): void
    {
        $pending = $this->db->all("SELECT * FROM dns_changes WHERE domain_id=? AND state='planned'".($onlyJob!==null?' AND job_id=?':'').' ORDER BY id', $onlyJob!==null?[$domain['id'],$onlyJob]:[$domain['id']]);
        foreach ($pending as $change) {
            $before = $change['before_json'] ? json_decode($change['before_json'], true) : null;
            $after = $change['after_json'] ? json_decode($change['after_json'], true) : null;
            if ($change['action'] === 'create') {
                $records = $dns->records($change['zone_id'], $after['name']);
                $found = null;
                foreach ($records as $record) {
                    if (($record['comment'] ?? '') === $after['comment'] && self::same($record,$after)) { $found=$record; break; }
                }
                if (!$found&&!$execute) { $this->db->run("UPDATE dns_changes SET state='cancelled' WHERE id=?",[$change['id']]);continue; }
                if (!$found) { $found=$dns->create($change['zone_id'],$after); }
                $this->db->run("UPDATE dns_changes SET state='applied',record_id=?,after_json=? WHERE id=?", [$found['id'], Validator::json($found),$change['id']]);
            } else {
                $current=$dns->get($change['zone_id'],$change['record_id']);
                if ($current!==null&&!$execute) { $this->db->run("UPDATE dns_changes SET state='cancelled' WHERE id=?",[$change['id']]);continue; }
                if ($current !== null && (!self::same($current,$before) || ($current['comment']??'') !== ($before['comment']??'') || (int)($current['ttl']??1)!==(int)($before['ttl']??1))) { throw new AppException('计划删除的 DNS 已被外部修改，请检查：'.$before['name']); }
                if ($current !== null) { $dns->delete($change['zone_id'],$change['record_id']); }
                $this->db->run("UPDATE dns_changes SET state='applied' WHERE id=?", [$change['id']]);
            }
            $this->audit->write('worker','dns.'.$change['action'],'DNS '.$change['action'].' '.($after['name']??$before['name']), (int)$domain['id'], (int)$job['id']);
        }
    }
    private function mutation(array $domain,array $job,DnsProvider $dns,string $zone,string $action,string $purpose,?array $before,?array $after): array
    {
        $id=$this->db->insert('INSERT INTO dns_changes(domain_id,job_id,account_id,zone_id,record_id,action,purpose,before_json,after_json) VALUES(?,?,?,?,?,?,?,?,?)',[$domain['id'],$job['id'],$domain['dns_account_id'],$zone,$before['id']??null,$action,$purpose,$before?Validator::json($before):null,$after?Validator::json($after):null]);
        if ($action==='create') {
            $after['comment']='cf-saas-panel:domain:'.$domain['id'].':change:'.$id;
            $this->db->run('UPDATE dns_changes SET after_json=? WHERE id=?',[Validator::json($after),$id]);
        }
        $this->reconcile($domain,$job,$dns);
        return $this->db->one('SELECT * FROM dns_changes WHERE id=?',[$id]);
    }
    public function ensureTxt(array $domain,array $job,DnsProvider $dns,string $zone,string $name,string $value,string $purpose): void
    {
        $fingerprint=hash('sha256',$purpose.'|'.$name.'|'.$value);
        $saved=$this->db->one('SELECT * FROM verification_records WHERE domain_id=? AND fingerprint=?',[$domain['id'],$fingerprint]);
        $desired=['type'=>'TXT','name'=>$name,'content'=>$value,'ttl'=>60];
        $records=$dns->records($zone,$name);
        $found=null;
        foreach ($records as $record) {
            if ($record['type']==='CNAME') { throw new AppException('验证名称存在 CNAME 委托，无法自动写入 TXT：'.$name); }
            if (self::same($record,$desired)) { $found=$record; }
        }
        $owned=false;
        if (!$found) {
            $change=$this->mutation($domain,$job,$dns,$zone,'create','verification',null,$desired);
            $found=json_decode($change['after_json'],true);
            $owned=true;
        } else {
            $owned=(bool)$this->db->value("SELECT COUNT(*) FROM dns_changes WHERE domain_id=? AND record_id=? AND action='create' AND state='applied' AND purpose='verification'",[$domain['id'],$found['id']]);
        }
        if ($saved && $saved['status']==='present' && $saved['record_id']===$found['id']) { return; }
        $this->db->run("INSERT INTO verification_records(domain_id,purpose,name,value,fingerprint,zone_id,record_id,owned,status) VALUES(?,?,?,?,?,?,?,?,'present') ON DUPLICATE KEY UPDATE record_id=VALUES(record_id),owned=VALUES(owned),status='present'",[$domain['id'],$purpose,$name,$value,$fingerprint,$zone,$found['id'],$owned?1:0]);
        $this->audit->write('worker',$owned?'txt.created':'txt.reused',($owned?'已创建':'复用原有').' TXT：'.$name,(int)$domain['id'],(int)$job['id']);
    }
    public function route(array $domain,array $job,DnsProvider $dns,array $desired): void
    {
        $existing=$dns->records($domain['dns_zone_id'],$domain['hostname']);
        if(array_filter($existing,static fn($record)=>$record['type']==='NS')){throw new AppException('此名称已有 NS 委派，不能直接替换其他记录');}
        foreach ($existing as $record) {
            if (!in_array($record['type'],['A','AAAA','CNAME'],true)) {
                continue;
            }
            $matches=array_filter($desired,static fn($want):bool=>self::same($record,$want));
            if ($matches) { continue; }
            $owned=(bool)$this->db->value("SELECT COUNT(*) FROM dns_changes WHERE domain_id=? AND record_id=? AND action='create' AND state='applied'",[$domain['id'],$record['id']]);
            if (!$owned && !$domain['replace_existing']) { throw new AppException('已有解析与目标不同，请检查并授权替换 A/AAAA/CNAME：'.$domain['hostname']); }
            $this->mutation($domain,$job,$dns,$domain['dns_zone_id'],'delete','routing',$record,null);
        }
        foreach ($desired as $want) {
            $current=$dns->records($domain['dns_zone_id'],$domain['hostname']);
            if (!array_filter($current,static fn($r):bool=>self::same($r,$want))) { $this->mutation($domain,$job,$dns,$domain['dns_zone_id'],'create','routing',null,$want); }
        }
    }
    public function deleteRouting(array $domain,array $job,DnsProvider $dns,array $expected):void
    {
        $records=array_values(array_filter($dns->records($domain['dns_zone_id'],$domain['hostname']),static fn($r):bool=>in_array($r['type'],['A','AAAA','CNAME'],true)));
        foreach ($records as $record) {
            if (!array_filter($expected,static fn($want):bool=>self::same($record,$want))) { throw new AppException('删除发现 DNS 内容或代理状态被外部修改，停止删除：'.$domain['hostname'],409,'dns_external_change'); }
        }
        foreach ($records as $record) { $this->mutation($domain,$job,$dns,$domain['dns_zone_id'],'delete','routing',$record,null); }
        if (array_filter($dns->records($domain['dns_zone_id'],$domain['hostname']),static fn($r):bool=>in_array($r['type'],['A','AAAA','CNAME'],true))) { throw new AppException('删除后出现新的解析，已保留新记录并停止移除管理',409,'dns_external_change'); }
    }
    public function cleanup(array $domain,array $job,DnsProvider $dns,bool $removing=false): void
    {
        if (!$removing&&($domain['hostname_status']!=='active'||$domain['ssl_status']!=='active')) { throw new AppException('验证尚未完成，不能清理 TXT'); }
        foreach ($this->db->all("SELECT * FROM verification_records WHERE domain_id=? AND status='present'",[$domain['id']]) as $row) {
            $status='retained';
            if ($row['owned']) {
                $shared=$this->db->value("SELECT COUNT(*) FROM verification_records v JOIN domains d ON d.id=v.domain_id WHERE v.domain_id<>? AND v.zone_id=? AND v.record_id=? AND v.status='present' AND (d.hostname_status<>'active' OR d.ssl_status<>'active')",[$domain['id'],$row['zone_id'],$row['record_id']]);
                if ($shared) { throw new AppException('TXT 仍被其他待验证域名使用，将稍后重试',503); }
                $record=$dns->get($row['zone_id'],$row['record_id']);
                if (!$record) { $status='deleted'; }
                elseif ($record['type']!=='TXT'||$record['name']!==$row['name']||trim($record['content'],'"')!==trim($row['value'],'"')||!str_starts_with($record['comment']??'','cf-saas-panel:domain:'.$domain['id'].':')) { $status='external_change'; }
                else {
                    $this->mutation($domain,$job,$dns,$row['zone_id'],'delete','cleanup',$record,null);
                    $this->db->run("UPDATE dns_changes SET state='cleaned' WHERE domain_id=? AND record_id=? AND purpose='verification' AND action='create'",[$domain['id'],$row['record_id']]);
                    $status='deleted';
                }
            }
            $this->db->run('UPDATE verification_records SET status=? WHERE id=?',[$status,$row['id']]);
            $this->audit->write('worker','txt.'.$status,'TXT '.$status.'：'.$row['name'],(int)$domain['id'],(int)$job['id']);
        }
    }
    public function rollback(array $domain,array $job,DnsProvider $dns,?int $onlyJob=null): void
    {
        $this->reconcile($domain,$job,$dns,false,$onlyJob);
        $scopeParams=$onlyJob!==null?[$domain['id'],$onlyJob]:[$domain['id']];
        $priorRecords=array_map(static fn($r):array=>json_decode($r['before_json'],true),$this->db->all("SELECT before_json FROM dns_changes WHERE domain_id=? AND action='delete' AND purpose<>'cleanup' AND before_json IS NOT NULL".($onlyJob!==null?' AND job_id=?':''),$scopeParams));
        foreach ($this->db->all("SELECT * FROM dns_changes WHERE domain_id=? AND state='applied' AND purpose<>'cleanup'".($onlyJob!==null?' AND job_id=?':'').' ORDER BY id DESC',$onlyJob!==null?[$domain['id'],$onlyJob]:[$domain['id']]) as $change) {
            if ($change['action']==='create') {
                $after=json_decode($change['after_json'],true);
                $current=$dns->get($change['zone_id'],$change['record_id']);
                if ($current===null) {
                    foreach ($dns->records($change['zone_id'],$after['name']) as $candidate) {
                        if (($candidate['comment']??'')===($after['comment']??'')&&self::same($candidate,$after)) { $current=$candidate;break; }
                    }
                }
                if ($current!==null) {
                    if (!self::same($current,$after)||($current['comment']??'')!==($after['comment']??'')||(int)($current['ttl']??1)!==(int)($after['ttl']??1)) { throw new AppException('回滚发现外部改动，已停止：'.$after['name']); }
                    $dns->delete($change['zone_id'],$current['id']);
                }
            } else {
                $before=json_decode($change['before_json'],true);
                $records=$dns->records($change['zone_id'],$before['name']);
                $matches=array_filter($records,static fn($r):bool=>self::same($r,$before)&&(int)($r['ttl']??1)===(int)($before['ttl']??1));
                if (!$matches) {
                    $addresses=array_values(array_filter($records,static fn($r):bool=>in_array($r['type'],['A','AAAA','CNAME','NS'],true)));
                    $compatible=in_array($before['type'],['A','AAAA','CNAME'],true)&&!array_filter($addresses,static fn($r):bool=>$r['type']==='NS'||($before['type']==='CNAME'?!in_array($r['type'],['CNAME'],true):!in_array($r['type'],['A','AAAA'],true)));
                    if ($compatible) { foreach($addresses as $record) { if (!array_filter($priorRecords,static fn($old):bool=>self::same($record,$old)&&(int)($record['ttl']??1)===(int)($old['ttl']??1)&&($record['comment']??'')===($old['comment']??''))) { $compatible=false;break; } } }
                    if ($records!==[]&&!$compatible) { throw new AppException('回滚目标已有不同记录，已停止：'.$before['name']); }
                    $dns->create($change['zone_id'],self::payload($before));
                }
            }
            $this->db->run("UPDATE dns_changes SET state='rolled_back' WHERE id=?",[$change['id']]);
            $this->audit->write('worker','dns.rollback','已恢复 DNS 变更 #'.$change['id'],(int)$domain['id'],(int)$job['id']);
        }
    }
}

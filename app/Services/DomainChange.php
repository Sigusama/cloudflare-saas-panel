<?php
// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);
namespace Panel\Services;

use Panel\Core\{Database,Validator,AppException};

final class DomainChange
{
    private Audit $audit;
    private DnsChanges $dnsChanges;
    public function __construct(private Database $db,private Cloud $cloud,private Diagnostics $diagnostics)
    {
        $this->audit=new Audit($db);$this->dnsChanges=new DnsChanges($db,$this->audit);
    }
    private static function originFields(array $remote):array
    {
        return ['custom_origin_server'=>(string)($remote['custom_origin_server']??''),'custom_origin_sni'=>(string)($remote['custom_origin_sni']??'')];
    }
    public static function desired(array $domain,array $config):array
    {
        return DnsMode::desired($domain,$config);
    }
    private function remote(array $domain,array $config):array
    {
        $remote=$this->cloud->client((int)$config['profile']['account_id'])->request('GET','/zones/'.$config['profile']['zone_id'].'/custom_hostnames/'.$domain['cf_hostname_id'])['result'];
        if (($remote['hostname']??'')!==$domain['hostname']) { throw new AppException('远端主机名不匹配，已停止',409); }
        $this->db->run('UPDATE domains SET remote_json=?,hostname_status=?,ssl_status=? WHERE id=?',[Validator::json($remote),$remote['status']??'pending',$remote['ssl']['status']??'pending',$domain['id']]);
        return $remote;
    }
    private function advance(array $domain,array $job,string $stage,string $message):void
    {
        $this->db->run("UPDATE jobs SET stage=?,status='queued',attempts=0,last_error=NULL,run_at=UTC_TIMESTAMP() WHERE id=?",[$stage,$job['id']]);
        $this->audit->write('worker','change.'.$stage,$message,(int)$domain['id'],(int)$job['id']);
    }
    private static function canonicalRecords(array $records):array
    {
        $out=array_map(static fn($r):string=>Validator::json(['type'=>$r['type'],'name'=>$r['name'],'content'=>$r['content'],'ttl'=>(int)($r['ttl']??1),'proxied'=>(bool)($r['proxied']??false),'comment'=>$r['comment']??'']),$records);
        sort($out);return $out;
    }
    private function assertDnsBaseline(array $domain,array $job,array $change):void
    {
        $expected=[];
        foreach (json_decode($change['baseline_dns_json']??'[]',true) as $record) { $expected[$record['id']]=$record; }
        foreach ($this->db->all("SELECT * FROM dns_changes WHERE job_id=? AND purpose='routing' AND state='applied' ORDER BY id",[$job['id']]) as $mutation) {
            if ($mutation['action']==='delete') { unset($expected[$mutation['record_id']]); }
            else { $expected[$mutation['record_id']]=json_decode($mutation['after_json'],true); }
        }
        $current=$this->cloud->dns((int)$domain['dns_account_id'])->records($domain['dns_zone_id'],$domain['hostname']);
        if (self::canonicalRecords(array_values($expected))!==self::canonicalRecords($current)) { throw new AppException('切换前 DNS 出现外部改动，已停止；请检查差异',409,'dns_external_change'); }
    }
    public function step(array $domain,array $job):void
    {
        $context=json_decode($job['context_json']??'{}',true);
        $change=$this->db->one('SELECT * FROM domain_changes WHERE id=? AND domain_id=?',[$context['change_id']??0,$domain['id']]);
        if (!$change) { throw new AppException('配置变更记录不存在',404); }
        $from=json_decode($change['from_config'],true);$to=json_decode($change['to_config'],true);
        $dns=$this->cloud->dns((int)$domain['dns_account_id']);
        if ($job['stage']==='change_restore') { $this->restore($domain,$job,$change,$from,$to,$context);return; }
        (new DomainConfig($this->db))->assertAvailable($to,(int)$domain['dns_account_id']);
        $this->dnsChanges->reconcile($domain,$job,$dns,true,(int)$job['id']);
        switch ($job['stage']) {
            case 'change_precheck':
                if(isset($context['approved_dns'])){ObservedDns::assertSame($context['approved_dns'],$dns->records($domain['dns_zone_id'],$domain['hostname']),$domain['hostname']);}
                $remote=null;
                if (DnsMode::preferred($to)) {
                    (new EntryAlias($this->db))->ensure($domain,$job,$to,$this->cloud);
                    (new ManagedOrigin($this->db))->ensure($domain,$job,$to,$this->cloud);
                    OriginCheck::validate($this->db,$this->cloud,$to['profile'],$to['origin']);
                    if ($domain['cf_hostname_id']) {
                        $remote=$this->remote($domain,$from);
                        $expectedOrigin=DnsMode::preferred($from)?($from['origin']['mode']==='custom'?$from['origin']['hostname']:''):(json_decode($domain['remote_json']??'{}',true)['custom_origin_server']??'');
                        if (self::originFields($remote)['custom_origin_server']!==$expectedOrigin) { throw new AppException('云端回源与当前生效快照不同，请先检查外部修改',409); }
                    }
                }
                $records=$dns->records($domain['dns_zone_id'],$domain['hostname']);
                $baseline=self::desired($domain,$from);
                if (empty($context['replace_existing'])) {
                    $addressRecords=array_values(array_filter($records,static fn($r):bool=>in_array($r['type'],['A','AAAA','CNAME'],true)));
                    if (count($addressRecords)!==count($baseline)) { throw new AppException('当前 DNS 与生效配置不一致，不能直接切换',409); }
                    foreach ($addressRecords as $record) { if (!array_filter($baseline,static fn($want):bool=>DnsChanges::same($record,$want))) { throw new AppException('当前 DNS 与生效配置不一致，不能直接切换',409); } }
                }
                $this->db->run("UPDATE domain_changes SET status='applying',remote_before_json=?,baseline_dns_json=? WHERE id=?",[$remote?Validator::json(self::originFields($remote)):null,Validator::json($records),$change['id']]);
                $stage=!DnsMode::preferred($to)?'change_routing':($remote?'change_origin':'change_provision');
                $this->advance($domain,$job,$stage,'切换预检通过，已保存 DNS 基线');break;
            case 'change_provision':
                $domain=(new SaasProvision($this->db,$this->cloud))->hostname($domain,$job,$to);
                $remote=$this->remote($domain,$to);
                $this->db->run('UPDATE domain_changes SET remote_before_json=?,remote_after_json=?,remote_write_planned=1 WHERE id=?',[Validator::json(self::originFields($remote)),Validator::json(self::originFields($remote)),$change['id']]);
                $this->advance($domain,$job,'change_validation','SaaS 主机名已准备，开始标准入口和 TXT 验证');break;
            case 'change_validation':
                $this->assertDnsBaseline($domain,$job,$change);
                if(!empty($to['profile']['cname_target'])&&empty($context['preserve_dns_until_ready'])){$copy=$domain;$copy['replace_existing']=1;$standard=$to;$standard['entry']['kind']='standard';$this->dnsChanges->route($copy,$job,$dns,self::desired($domain,$standard));}
                $remote=$this->remote($domain,$to);
                if (($remote['status']??'')!=='active'||($remote['ssl']['status']??'')!=='active') {
                    if ((int)$job['checks']>=2880) { throw new AppException('SaaS 验证等待超时，请检查后重试'); }
                    (new SaasProvision($this->db,$this->cloud))->verification($domain,$job,$remote);
                    $this->db->run("UPDATE jobs SET status='waiting',checks=checks+1,run_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 SECOND) WHERE id=?",[$job['id']]);return;
                }
                $this->advance($domain,$job,'change_origin','主机名和证书验证通过，继续应用优选入口');break;
            case 'change_origin':
                $before=json_decode($change['remote_before_json'],true);
                $server=$to['origin']['mode']==='custom'?$to['origin']['hostname']:'';
                $desired=$server===$before['custom_origin_server']?$before:['custom_origin_server'=>$server,'custom_origin_sni'=>''];
                $current=self::originFields($this->remote($domain,$to));
                if ($current!==$before&&$current!==$desired) { throw new AppException('云端回源出现外部修改，不能覆盖',409); }
                $this->db->run('UPDATE domain_changes SET remote_after_json=? WHERE id=?',[Validator::json($desired),$change['id']]);
                if ($current!==$desired) {
                    $this->db->run('UPDATE domain_changes SET remote_write_planned=1 WHERE id=?',[$change['id']]);
                    $body=['custom_origin_server'=>$desired['custom_origin_server']];
                    if ($before['custom_origin_sni']!=='') { $body['custom_origin_sni']=''; }
                    $this->cloud->client((int)$to['profile']['account_id'])->request('PATCH','/zones/'.$to['profile']['zone_id'].'/custom_hostnames/'.$domain['cf_hostname_id'],$body);
                    if (self::originFields($this->remote($domain,$to))!==$desired) { throw new AppException('回源变更尚未生效，稍后重查',503); }
                }
                $this->advance($domain,$job,'change_routing','回源已确认，继续切换访问入口');break;
            case 'change_routing':
                $this->assertDnsBaseline($domain,$job,$change);
                (new EntryAlias($this->db))->ensure($domain,$job,$to,$this->cloud);
                $copy=$domain;$copy['replace_existing']=1;
                $this->dnsChanges->route($copy,$job,$dns,self::desired($domain,$to));
                $this->advance($domain,$job,'change_diagnose','入口已写入，验证新配置的业务访问');break;
            case 'change_diagnose':
                (new EntryAlias($this->db))->ensure($domain,$job,$to,$this->cloud);
                if (DnsMode::preferred($to)) {
                    $remote=$this->remote($domain,$to);
                    if (($remote['status']??'')!=='active'||($remote['ssl']['status']??'')!=='active') { throw new AppException('切换后主机名或证书尚未就绪',503); }
                    $result=$this->diagnostics->check($domain['hostname'],Diagnostics::forConfig($to));
                    if (!empty($result['success'])) { $fresh=$this->db->one('SELECT * FROM domains WHERE id=?',[$domain['id']]);$this->dnsChanges->cleanup($fresh,$job,$dns); }
                } else { $result=DnsMode::verify($domain,$to,$dns); }
                $this->db->run('UPDATE domains SET diagnostic_json=? WHERE id=?',[Validator::json($result),$domain['id']]);
                if (empty($result['success'])) { throw new AppException($result['error']??'新配置访问检查失败',422,'change_diagnostic_failed'); }
                $this->db->transaction(function()use($domain,$job,$change,$to):void {
                    $this->db->run("UPDATE domains SET entry_id=?,origin_id=?,applied_config=?,config_version=config_version+1,adopted=0,status='active',last_error=NULL,next_check_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 MINUTE) WHERE id=?",[$to['entry']['id'],$to['origin']['id'],Validator::json($to),$domain['id']]);
                    if (!DnsMode::preferred($to)) { $this->db->run("UPDATE domains SET hostname_status='not_required',ssl_status='not_required',cleanup_status='not_required',next_check_at=NULL WHERE id=?",[$domain['id']]); }
                    $this->db->run("UPDATE domain_changes SET status='applied' WHERE id=?",[$change['id']]);
                    $this->db->run("UPDATE jobs SET status='completed' WHERE id=?",[$job['id']]);
                    $this->audit->write('worker','change.completed','入口与回源切换成功，版本已更新',(int)$domain['id'],(int)$job['id']);
                });break;
            default:throw new AppException('未知变更步骤',500);
        }
    }
    public function onFailure(array $domain,array $job,string $message):bool
    {
        if ($job['kind']!=='change'||$job['stage']==='change_restore') { return false; }
        $context=json_decode($job['context_json']??'{}',true);
        $change=$this->db->one('SELECT * FROM domain_changes WHERE id=?',[$context['change_id']??0]);
        if (!$change) { return false; }
        $mutated=$change['remote_write_planned']||!empty($context['creation_pending'])||(int)$this->db->value("SELECT COUNT(*) FROM dns_changes WHERE job_id=? AND state IN ('planned','applied')",[$job['id']]);
        if (!$mutated) { $this->db->run("UPDATE domain_changes SET status='failed',last_error=? WHERE id=?",[$message,$change['id']]);return false; }
        $context['restore_source_job']=(int)$job['id'];$context['automatic']=true;
        $this->db->run("UPDATE jobs SET stage='change_restore',status='queued',attempts=0,last_error=?,context_json=?,run_at=UTC_TIMESTAMP() WHERE id=?",[$message,Validator::json($context),$job['id']]);
        $this->db->run("UPDATE domain_changes SET status='restoring',last_error=? WHERE id=?",[$message,$change['id']]);
        $this->audit->write('worker','change.auto_restore','切换失败，自动恢复本次变更：'.$message,(int)$domain['id'],(int)$job['id']);
        return true;
    }
    private function restore(array $domain,array $job,array $change,array $from,array $to,array $context):void
    {
        if (DnsMode::preferred($from)) { (new EntryAlias($this->db))->ensure($domain,$job,$from,$this->cloud);(new ManagedOrigin($this->db))->ensure($domain,$job,$from,$this->cloud); }
        $before=$change['remote_before_json']?json_decode($change['remote_before_json'],true):null;
        $after=$change['remote_after_json']?json_decode($change['remote_after_json'],true):$before;
        if ($before) {
            $current=self::originFields($this->remote($domain,$from));
            if ($current!==$before&&$current!==$after) { throw new AppException('回滚发现云端回源被外部修改，已停止',409); }
            if ($current!==$before) {
                $this->cloud->client((int)$from['profile']['account_id'])->request('PATCH','/zones/'.$from['profile']['zone_id'].'/custom_hostnames/'.$domain['cf_hostname_id'],$before);
                if (self::originFields($this->remote($domain,$from))!==$before) { throw new AppException('旧回源尚未恢复，稍后重试',503); }
                $this->audit->write('worker','change.origin_restored','旧回源已恢复',(int)$domain['id'],(int)$job['id']);
            }
        }
        $this->dnsChanges->rollback($domain,$job,$this->cloud->dns((int)$domain['dns_account_id']),(int)($context['restore_source_job']??$change['job_id']));
        if (DnsMode::preferred($from)) {
            $remote=$this->remote($domain,$from);
            if (($remote['status']??'')!=='active'||($remote['ssl']['status']??'')!=='active') { throw new AppException('恢复优选后主机名或证书尚未就绪，稍后重试',503); }
            if (($remote['custom_origin_server']??'')!==($from['origin']['mode']==='custom'?$from['origin']['hostname']:'')) { throw new AppException('恢复优选发现回源已被外部修改，停止覆盖',409); }
        }
        $result=DnsMode::preferred($from)?$this->diagnostics->check($domain['hostname'],Diagnostics::forConfig($from)):DnsMode::verify($domain,$from,$this->cloud->dns((int)$domain['dns_account_id']));
        if (empty($result['success'])) { throw new AppException('旧配置已写回，但访问检查未通过，将重试',503); }
        $this->db->transaction(function()use($domain,$job,$change,$from,$context,$result):void {
            $message=!empty($context['automatic'])?'切换失败，已自动恢复：'.$change['last_error']:null;
            $this->db->run("UPDATE domains SET entry_id=?,origin_id=?,applied_config=?,config_version=config_version+1,status='active',diagnostic_json=?,last_error=?,next_check_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 MINUTE) WHERE id=?",[$from['entry']['id'],$from['origin']['id'],Validator::json($from),Validator::json($result),$message,$domain['id']]);
            if (!DnsMode::preferred($from)) { $this->db->run("UPDATE domains SET hostname_status='not_required',ssl_status='not_required',cleanup_status='not_required',next_check_at=NULL WHERE id=?",[$domain['id']]); }
            $this->db->run("UPDATE domain_changes SET status='rolled_back' WHERE id=?",[$change['id']]);
            $this->db->run("UPDATE jobs SET status='completed',last_error=? WHERE id=?",[$message,$job['id']]);
            $this->audit->write('worker','change.restored','本次变更已恢复；原接入和其他变更保留',(int)$domain['id'],(int)$job['id']);
        });
    }
}

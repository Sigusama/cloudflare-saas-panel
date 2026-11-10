<?php
// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);
namespace Panel\Jobs;

use Panel\Core\{Database, Validator, AppException};
use Panel\Services\{Cloud, Audit, DnsChanges, DomainService, Diagnostics,DomainConfig,DomainChange,ManagedOrigin,DnsMode};

final class Worker
{
    private Audit $audit;
    private DnsChanges $changes;
    public function __construct(private Database $db,private Cloud $cloud,private Diagnostics $diagnostics=new Diagnostics())
    {
        $this->audit=new Audit($db);
        $this->changes=new DnsChanges($db,$this->audit);
    }
    public function once(): bool
    {
        if($this->db->value("SELECT value FROM settings WHERE name='preview_mode'")==='1'){$this->heartbeat();return false;}
        if (!(int)$this->db->value("SELECT GET_LOCK('cf_panel_worker',0)")) { return false; }
        try {
            $this->heartbeat();
            if ($this->db->value("SELECT value FROM settings WHERE name='preview_mode'")==='1') { return false; }
            $this->db->run('DELETE FROM api_rate_limits WHERE expires_at<UTC_TIMESTAMP()');
            if((new \Panel\Services\DnsOperations($this->db,$this->cloud))->once()){return true;}
            $this->scheduleRenewal();
            $job=$this->db->one("SELECT * FROM jobs WHERE status IN ('queued','waiting') AND run_at<=UTC_TIMESTAMP() ORDER BY run_at,id LIMIT 1");
            if (!$job) { return false; }
            $domain=$this->db->one('SELECT * FROM domains WHERE id=?',[$job['domain_id']]);
            try {
                $this->step($domain,$job);
            } catch (\Throwable $exception) {
                $recoverable=$exception instanceof AppException && $exception->status>=500;
                $attempts=(int)$job['attempts']+1;
                $status=$recoverable&&$attempts<=8?'waiting':($recoverable?'failed':'needs_attention');
                $message=$exception instanceof AppException?$exception->getMessage():'执行异常，请查看服务器日志并重试';
                if (!$exception instanceof AppException) { error_log('Panel worker job '.$job['id'].' '.get_class($exception).' '.$exception->getMessage()); }
                if ($status!=='waiting'&&(new DomainChange($this->db,$this->cloud,$this->diagnostics))->onFailure($domain,$job,$message)) { return true; }
                $delay=max(min(300,10*(2**min($attempts,5))),$exception instanceof AppException?($exception->retryAfter??0):0);
                $this->db->run('UPDATE jobs SET status=?,attempts=?,last_error=?,run_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND) WHERE id=?',[$status,$attempts,$message,$delay,$job['id']]);
                $domainStatus=in_array($job['kind'],['diagnose','change','renew'],true)&&$job['stage']!=='change_restore'?($job['kind']==='change'?'active':$domain['status']):($job['stage']==='cleanup'?'active':(in_array($job['kind'],['rollback','change_rollback'],true)||$job['stage']==='change_restore'?'rollback_failed':$status));
                if ($job['stage']==='change_restore') { $context=json_decode($job['context_json']??'{}',true);$this->db->run('UPDATE domain_changes SET status=?,last_error=? WHERE id=?',[$status==='waiting'?'restoring':'rollback_failed',$message,$context['change_id']??0]); }
                $this->db->run('UPDATE domains SET status=?,last_error=?,cleanup_status=IF(?=\'cleanup\',\'cleanup_pending\',cleanup_status) WHERE id=?',[$domainStatus,$message,$job['stage'],$domain['id']]);
                $this->audit->write('worker','job.error',$message,(int)$domain['id'],(int)$job['id']);
            }
            return true;
        } finally { $this->db->value("SELECT RELEASE_LOCK('cf_panel_worker')"); }
    }
    private function heartbeat():void
    {
        $this->db->run("INSERT INTO settings(name,value) VALUES('worker_heartbeat',?) ON DUPLICATE KEY UPDATE value=VALUES(value)",[gmdate('c')]);
    }
    private function scheduleRenewal(): void
    {
        $domain=$this->db->one("SELECT d.id,d.adopted FROM domains d WHERE d.deleted_at IS NULL AND d.status='active' AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(d.applied_config,'$.dns_mode')),'preferred')='preferred' AND d.next_check_at<=UTC_TIMESTAMP() AND NOT EXISTS(SELECT 1 FROM jobs j WHERE j.domain_id=d.id AND j.status NOT IN ('completed','cancelled')) ORDER BY d.next_check_at LIMIT 1");
        if ($domain) {
            (new DomainService($this->db))->queue((int)$domain['id'],$domain['adopted']?'inspect':'renew',$domain['adopted']?'inspect':'waiting');
            $this->db->run('UPDATE domains SET next_check_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 MINUTE) WHERE id=?',[$domain['id']]);
        }
    }
    private function advance(array $domain,array $job,string $stage,string $message): void
    {
        $this->db->run("UPDATE jobs SET stage=?,status='queued',attempts=0,last_error=NULL,run_at=UTC_TIMESTAMP() WHERE id=?",[$stage,$job['id']]);
        $this->audit->write('worker','job.'.$stage,$message,(int)$domain['id'],(int)$job['id']);
    }
    private function remote(array $domain,array $profile): array
    {
        $remote=$this->cloud->client((int)$profile['account_id'])->request('GET','/zones/'.$profile['zone_id'].'/custom_hostnames/'.$domain['cf_hostname_id'])['result'];
        $this->db->run('UPDATE domains SET hostname_status=?,ssl_status=?,remote_json=? WHERE id=?',[$remote['status']??'pending',$remote['ssl']['status']??'pending',Validator::json($remote),$domain['id']]);
        return $remote;
    }
    private function desired(array $domain,array $profile,array $entry,bool $standard=false): array
    {
        if(!$standard&&!empty($entry['hostname'])){return DnsMode::desired($domain,['dns_mode'=>'preferred','profile'=>$profile,'entry'=>$entry]);}
        $targets=json_decode($entry['targets'],true);
        if ($standard||$entry['kind']==='standard') { $targets=[$profile['cname_target']]; }
        return array_map(static fn(string $target):array=>['name'=>$domain['hostname'],'type'=>($standard||$entry['kind']!=='ip')?'CNAME':(str_contains($target,':')?'AAAA':'A'),'content'=>$target,'proxied'=>false,'ttl'=>60],$targets);
    }
    private function ensureVerification(array $domain,array $job,array $remote): void
    {
        $records=[];
        if (($remote['status']??'')!=='active'&&!empty($remote['ownership_verification']['name'])) {
            $r=$remote['ownership_verification'];
            $records[]=['name'=>$r['name'],'value'=>$r['value'],'purpose'=>'ownership'];
        }
        if (($remote['ssl']['status']??'')!=='active') {
            foreach ($remote['ssl']['validation_records']??[] as $r) {
                if (!empty($r['txt_name'])&&!empty($r['txt_value'])) { $records[]=['name'=>$r['txt_name'],'value'=>$r['txt_value'],'purpose'=>'certificate']; }
            }
        }
        $dns=$this->cloud->dns((int)$domain['dns_account_id']);
        $zones=$this->db->all('SELECT * FROM zones WHERE account_id=? ORDER BY CHAR_LENGTH(name) DESC',[$domain['dns_account_id']]);
        foreach ($records as $r) {
            $zone=null;
            foreach ($zones as $candidate) { if (Validator::zoneMatches(strtolower(rtrim($r['name'],'.')),$candidate['name'])) { $zone=$candidate;break; } }
            if (!$zone) { throw new AppException('没有权限管理验证名称：'.$r['name']); }
            $this->changes->ensureTxt($domain,$job,$dns,$zone['cf_id'],strtolower(rtrim($r['name'],'.')),$r['value'],$r['purpose']);
        }
    }
    private function direct(array $domain,array $job,array $config):void
    {
        $dns=$this->cloud->dns((int)$domain['dns_account_id']);
        $this->changes->reconcile($domain,$job,$dns);
        if ($job['kind']==='diagnose') { $result=DnsMode::verify($domain,$config,$dns); }
        elseif ($job['stage']==='precheck') { $this->advance($domain,$job,'routing','DNS 模式预检完成：'.DnsMode::of($config));return; }
        elseif ($job['stage']==='routing') { $this->changes->route($domain,$job,$dns,DnsMode::desired($domain,$config));$this->advance($domain,$job,'diagnose','已写入源站解析和代理状态，继续读回核对');return; }
        else { $result=DnsMode::verify($domain,$config,$dns); }
        $this->db->run("UPDATE domains SET status='active',hostname_status='not_required',ssl_status='not_required',cleanup_status='not_required',next_check_at=NULL,last_error=NULL,diagnostic_json=? WHERE id=?",[Validator::json($result),$domain['id']]);
        $this->db->run("UPDATE jobs SET status='completed',last_error=NULL WHERE id=?",[$job['id']]);
        $this->audit->write('worker','dns.mode_applied','DNS 模式已保存：'.DnsMode::of($config).'；已核对云端记录和代理状态',(int)$domain['id'],(int)$job['id']);
    }
    private function step(array $domain,array $job): void
    {
        if (in_array($job['kind'],['change','change_rollback'],true)) { (new DomainChange($this->db,$this->cloud,$this->diagnostics))->step($domain,$job);return; }
        $configs=new DomainConfig($this->db);$applied=$configs->applied($domain);
        if (!in_array($job['kind'],['rollback','remove'],true)) { $configs->assertAvailable($applied,(int)$domain['dns_account_id']); }
        if($job['kind']==='inspect'||(!empty($domain['adopted'])&&in_array($job['kind'],['diagnose','renew'],true))){(new \Panel\Services\AdoptedDomain($this->db,$this->cloud,$this->diagnostics))->inspect($domain,$job,$applied);return;}
        if (!DnsMode::preferred($applied)&&!in_array($job['kind'],['rollback','remove'],true)) { $this->direct($domain,$job,$applied);return; }
        $profile=$applied['profile'];$entry=$applied['entry'];$origin=$applied['origin'];
        $client=$this->cloud->client((int)$profile['account_id']);
        $dns=$this->cloud->dns((int)$domain['dns_account_id']);
        $this->changes->reconcile($domain,$job,$dns,!in_array($job['kind'],['rollback','remove'],true));
        switch ($job['stage']) {
            case 'remove':
                $context=json_decode($job['context_json']??'{}',true);
                $remote=null;
                if ($domain['cf_hostname_id']) {
                    try { $remote=$this->remote($domain,$profile); }
                    catch (AppException $e) { if ($e->status!==404) { throw $e; } }
                    $expected=DnsMode::preferred($applied)?($origin['mode']==='custom'?$origin['hostname']:''):(json_decode($domain['remote_json']??'{}',true)['custom_origin_server']??'');
                    if ($remote&&($remote['custom_origin_server']??'')!==$expected) { throw new AppException('云端回源已被外部修改，请检查后再移除',409); }
                }
                $deleteRecords=($context['mode']??'')==='delete_records';
                if ($deleteRecords) {
                    $this->changes->deleteRouting($domain,$job,$dns,DnsMode::desired($domain,$applied));
                    $this->changes->cleanup($domain,$job,$dns,true);
                } else { $this->changes->rollback($domain,$job,$dns); }
                $removeRemote=in_array($context['mode']??'',['delete_remote','delete_records'],true);
                if ($removeRemote&&$domain['hostname_created']&&$remote) { $client->request('DELETE','/zones/'.$profile['zone_id'].'/custom_hostnames/'.$domain['cf_hostname_id']); }
                $deletedRemote=$removeRemote&&(bool)$domain['hostname_created'];
                if ($deletedRemote||!$remote) { (new ManagedOrigin($this->db))->cleanup($domain,$job,$this->cloud); }
                $this->db->run("UPDATE domains SET deleted_at=UTC_TIMESTAMP(),status='unmanaged',next_check_at=NULL,cf_hostname_id=IF(?,NULL,cf_hostname_id),hostname_created=IF(?,0,hostname_created) WHERE id=?",[$deletedRemote?1:0,$deletedRemote?1:0,$domain['id']]);
                $this->db->run("UPDATE jobs SET status='completed',last_error=NULL WHERE id=?",[$job['id']]);
                $message=$deleteRecords?'已删除当前接入解析，保留无关 DNS 与历史记录':'已恢复原 DNS';
                $this->audit->write('worker','domain.removed',$message.($deletedRemote?'；已清理面板创建的主机名':'；云端主机名保留（复用的主机名不自动删除）'),(int)$domain['id'],(int)$job['id']);
                break;
            case 'precheck':
                if(empty($profile['cname_target'])){throw new AppException('新建接入缺少标准 CNAME，已停止，未修改解析');}
                (new \Panel\Services\EntryAlias($this->db))->ensure($domain,$job,$applied,$this->cloud);
                (new ManagedOrigin($this->db))->ensure($domain,$job,$applied,$this->cloud);
                $fallback=$client->request('GET','/zones/'.$profile['zone_id'].'/custom_hostnames/fallback_origin')['result'];
                if (($fallback['status']??'')!=='active'||($fallback['origin']??'')!==$profile['fallback_origin']) { throw new AppException('SaaS 默认回源尚未就绪或与配置不一致'); }
                if ($origin['mode']==='custom') {
                    $zone=null;
                    foreach ($this->db->all('SELECT * FROM zones WHERE account_id=? ORDER BY CHAR_LENGTH(name) DESC',[$profile['account_id']]) as $z) { if (Validator::zoneMatches($origin['hostname'],$z['name'])) { $zone=$z;break; } }
                    if (!$zone) { throw new AppException('独立回源需为 SaaS 账号可管理的主机名，请创建代理 DNS 记录并同步 Zone'); }
                    $source=$this->cloud->dns((int)$profile['account_id'])->records($zone['cf_id'],$origin['hostname']);
                    if (!array_filter($source,static fn($r):bool=>in_array($r['type'],['A','AAAA','CNAME'],true)&&!empty($r['proxied']))) { throw new AppException('独立回源缺少有效橙云记录：'.$origin['hostname']); }
                }
                $current=$dns->records($domain['dns_zone_id'],$domain['hostname']);
                $standard=$this->desired($domain,$profile,$entry,true);
                foreach ($current as $r) {
                    if (in_array($r['type'],['A','AAAA','CNAME'],true)&&!DnsChanges::same($r,$standard[0])&&!$domain['replace_existing']) { throw new AppException('接入需先配置 SaaS 标准 CNAME，已有记录需授权替换后继续'); }
                    if (!in_array($r['type'],['A','AAAA','CNAME'],true)) { throw new AppException('接入名称有其他记录，与标准 CNAME 冲突：'.$r['type']); }
                }
                $this->advance($domain,$job,'hostname','预检完成，使用 Cloudflare 标准 CNAME 完成实时接入验证');
                break;
            case 'hostname':
                if (!$domain['cf_hostname_id']) {
                    $matches=$client->pages('/zones/'.$profile['zone_id'].'/custom_hostnames',['hostname'=>$domain['hostname']]);
                    $matches=array_values(array_filter($matches,static fn($r):bool=>strtolower($r['hostname'])===$domain['hostname']));
                    $remote=$matches[0]??null;
                    $context=json_decode($job['context_json']??'{}',true);
                    $created=false;
                    if (!$remote) {
                        $context['creation_pending']=true;
                        $this->db->run('UPDATE jobs SET context_json=? WHERE id=?',[Validator::json($context),$job['id']]);
                        $body=['hostname'=>$domain['hostname'],'ssl'=>['method'=>'txt','type'=>'dv','settings'=>['min_tls_version'=>'1.2']]];
                        if ($origin['mode']==='custom') { $body['custom_origin_server']=$origin['hostname']; }
                        $remote=$client->request('POST','/zones/'.$profile['zone_id'].'/custom_hostnames',$body)['result'];
                        $created=true;
                    } elseif (($remote['custom_origin_server']??null)!==($origin['mode']==='custom'?$origin['hostname']:null)) { throw new AppException('已有 Custom Hostname 的回源与所选回源不同，不会擅自接管'); }
                    $this->db->run('UPDATE domains SET cf_hostname_id=?,hostname_created=? WHERE id=?',[$remote['id'],$created?1:0,$domain['id']]);
                }
                $this->changes->route($domain,$job,$dns,$this->desired($domain,$profile,$entry,true));
                $this->advance($domain,$job,'waiting','标准接入解析已配置，继续同步 TXT 与证书');
                break;
            case 'waiting':
                $remote=$this->remote($domain,$profile);
                if (($remote['status']??'')==='active'&&($remote['ssl']['status']??'')==='active') {
                    $this->advance($domain,$job,'routing','域名与证书均已就绪');
                } else {
                    if ((int)$job['checks']>2880) { throw new AppException('验证等待已超时，请查看 Cloudflare 状态并重试'); }
                    $this->ensureVerification($domain,$job,$remote);
                    $this->db->run("UPDATE jobs SET status='waiting',checks=checks+1,run_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND) WHERE id=?",[min(300,15+15*(int)$job['checks']),$job['id']]);
                    $this->db->run("UPDATE domains SET status='waiting',last_error=NULL WHERE id=?",[$domain['id']]);
                }
                break;
            case 'routing':
                (new \Panel\Services\EntryAlias($this->db))->ensure($domain,$job,$applied,$this->cloud);
                $this->changes->route($domain,$job,$dns,$this->desired($domain,$profile,$entry));
                $this->advance($domain,$job,'diagnose','已应用目标入口，检查实际 HTTPS');
                break;
            case 'diagnose':
                (new \Panel\Services\EntryAlias($this->db))->ensure($domain,$job,$applied,$this->cloud);
                $result=$this->diagnostics->check($domain['hostname'],Diagnostics::forConfig($applied));
                $this->db->run('UPDATE domains SET diagnostic_json=? WHERE id=?',[Validator::json($result),$domain['id']]);
                if (empty($result['success'])) { throw new AppException($result['error']??'HTTPS 检查未通过',503,'diagnostic_failed'); }
                if ($job['kind']==='diagnose') {
                    $this->db->run("UPDATE jobs SET status='completed',last_error=NULL WHERE id=?",[$job['id']]);
                    $this->audit->write('worker','diagnose.success',!empty($applied['check']['keyword'])?'HTTPS 与响应内容检查通过':'HTTPS 检查通过',(int)$domain['id'],(int)$job['id']);
                } else {
                    $this->db->run("UPDATE domains SET status='active',cleanup_status='cleanup_pending',last_error=NULL WHERE id=?",[$domain['id']]);
                    $this->advance($domain,$job,'cleanup','HTTPS 基础检查通过，开始清理本轮临时 TXT');
                }
                break;
            case 'cleanup':
                $this->remote($domain,$profile);
                $fresh=$this->db->one('SELECT * FROM domains WHERE id=?',[$domain['id']]);
                $this->changes->cleanup($fresh,$job,$dns);
                $this->db->run("UPDATE domains SET status='active',cleanup_status='completed',last_error=NULL,next_check_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 MINUTE) WHERE id=?",[$domain['id']]);
                $this->db->run("UPDATE jobs SET status='completed',last_error=NULL WHERE id=?",[$job['id']]);
                $this->audit->write('worker','onboard.completed','接入与本轮 TXT 清理完成',(int)$domain['id'],(int)$job['id']);
                break;
            case 'rollback':
                $this->changes->rollback($domain,$job,$dns);
                if ($domain['hostname_created']&&$domain['cf_hostname_id']) {
                    try {
                        $remote=$this->remote($domain,$profile);
                        if (($remote['hostname']??'')!==$domain['hostname']) { throw new AppException('远端主机名与本任务不匹配'); }
                        $client->request('DELETE','/zones/'.$profile['zone_id'].'/custom_hostnames/'.$domain['cf_hostname_id']);
                    } catch (AppException $e) { if ($e->status!==404) { throw $e; } }
                    $this->db->run('UPDATE domains SET cf_hostname_id=NULL,hostname_created=0 WHERE id=?',[$domain['id']]);
                }
                if (!$domain['cf_hostname_id']||$domain['hostname_created']) { (new ManagedOrigin($this->db))->cleanup($domain,$job,$this->cloud); }
                $this->db->run("UPDATE domains SET status='rolled_back',last_error=NULL,next_check_at=NULL WHERE id=?",[$domain['id']]);
                $this->db->run("UPDATE jobs SET status='completed',last_error=NULL WHERE id=?",[$job['id']]);
                $this->audit->write('worker','rollback.completed','已恢复本面板创建的变更',(int)$domain['id'],(int)$job['id']);
                break;
            default: throw new AppException('未知任务阶段',500);
        }
    }
}

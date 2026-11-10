<?php
// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);
namespace Panel\Services;

use Panel\Core\{Database, Validator, AppException};

final class DomainService
{
    public function __construct(private Database $db) {}
    public function create(array $input, string $actor): array
    {
        return $this->db->transaction(fn():array=>$this->createBatch($input,$actor));
    }
    private function createBatch(array $input,string $actor):array
    {
        $defaults=(new Settings($this->db))->read();
        $input=array_replace($defaults,$input);
        $names = $input['hostnames'] ?? [];
        if (is_string($names)) { $names = preg_split('/[\s,]+/', trim($names), -1, PREG_SPLIT_NO_EMPTY); }
        if (!is_array($names) || count($names) < 1 || count($names) > 50) { throw new AppException('每次提交 1 至 50 个域名'); }
        if (array_filter($names,static fn($name):bool=>!is_string($name))) { throw new AppException('域名列表必须只包含字符串'); }
        $replace=Validator::boolean($input['replace_existing']??false);
        $dnsMode=DnsMode::validate($input['dns_mode']??'preferred');
        $check=DomainConfig::checkOptions($input['check']??[],$defaults['check']);
        $profile = $this->db->one('SELECT * FROM profiles WHERE id=? AND active=1 AND deleted_at IS NULL', [(int)($input['profile_id'] ?? 0)]);
        if($profile&&$dnsMode==='preferred'&&empty($profile['cname_target'])){throw new AppException('该 SaaS 站点已按原配置纳管；新建接入需先设置标准接入 CNAME，已有域名不受影响');}
        if(array_key_exists('source_ip',$input)&&array_key_exists('source_hostname',$input)){throw new AppException('回源 IP 和 CNAME 域名请选择一种');}
        if(array_key_exists('source_hostname',$input)){
            if(!$profile||count(array_unique($names))!==1){throw new AppException('指定 CNAME 回源时，每次提交一个域名并选择有效 CF 接入账号设置');}
            $input['origin_id']=(new ManagedOrigin($this->db))->selectHostname($profile,Validator::hostname(reset($names)),$input['source_hostname']);
        }
        if (array_key_exists('source_ip',$input)) {
            if (!$profile||count(array_unique($names))!==1) { throw new AppException('指定源站 IP 时，每次提交一个域名并选择有效 SaaS 配置'); }
            $input['origin_id']=(new ManagedOrigin($this->db))->select($profile,Validator::hostname(reset($names)),$input['source_ip']);
        }
        $origin = $this->db->one('SELECT * FROM origins WHERE id=? AND active=1 AND deleted_at IS NULL', [(int)($input['origin_id'] ?? 0)]);
        $entry = $this->db->one('SELECT * FROM entries WHERE id=? AND active=1 AND deleted_at IS NULL', [(int)($input['entry_id'] ?? 0)]);
        $account = $this->db->one('SELECT * FROM accounts WHERE id=? AND active=1 AND deleted_at IS NULL', [(int)($input['dns_account_id'] ?? 0)]);
        if (!$profile || !$origin || !$entry || !$account || (int)$origin['profile_id'] !== (int)$profile['id']) { throw new AppException('请选择可用且相互匹配的 SaaS、DNS 账号、入口及回源'); }
        $selection=(new DomainConfig($this->db))->capture(['profile_id'=>$profile['id'],'entry_id'=>$entry['id'],'origin_id'=>$origin['id']],null,null,$check);
        $selection['dns_mode']=$dnsMode;
        (new DomainConfig($this->db))->assertAvailable($selection,(int)$account['id']);
        $items = [];
        foreach (array_unique($names) as $raw) {
            $savepoint='domain_item_'.bin2hex(random_bytes(6));$this->db->pdo->exec('SAVEPOINT '.$savepoint);
            try {
                $hostname = Validator::hostname((string)$raw);
                (new EntryAlias($this->db))->assertBusiness($hostname,$selection);
                if ($dnsMode==='preferred'&&$hostname === $profile['zone_name']) { throw new AppException('接入域名不能等于 SaaS Zone 根域名'); }
                $zones = $this->db->all("SELECT * FROM zones WHERE account_id=? AND status='active' ORDER BY CHAR_LENGTH(name) DESC", [$account['id']]);
                $zone = null;
                foreach ($zones as $candidate) { if (Validator::zoneMatches($hostname, $candidate['name'])) { $zone = $candidate; break; } }
                if (!$zone) { throw new AppException('该 DNS 账号中没有匹配的 Zone，请先同步或更换账号'); }
                if (isset($input['dns_zone_id'])&&$input['dns_zone_id']!==$zone['cf_id']) { throw new AppException('该记录属于另一个更具体的 Zone，请进入对应主域名操作'); }
                $old = $this->db->one('SELECT * FROM domains WHERE hostname=?', [$hostname]);
                if ($old) {
                    $old=$this->db->one('SELECT * FROM domains WHERE id=? FOR UPDATE',[$old['id']]);
                    foreach (['profile_id','dns_account_id','origin_id','entry_id'] as $key) { if ((int)$old[$key] !== (int)$input[$key]) { throw new AppException('域名已使用其他配置接入，请先查看已有配置', 409); } }
                    if (DnsMode::of((new DomainConfig($this->db))->applied($old))!==$dnsMode) { throw new AppException('该记录已有其他 DNS 模式，请编辑现有记录',409); }
                    if ($old['deleted_at']) {
                        $this->db->run("UPDATE domains SET deleted_at=NULL,status='rolled_back' WHERE id=?",[$old['id']]);
                        $old['status']='rolled_back';
                    }
                    if ($old['status']==='rolled_back') {
                        $newJob=$this->queue((int)$old['id'],'onboard','precheck');
                        $snapshot=(new DomainConfig($this->db))->capture($old,null,null,$check);
                        $snapshot['dns_mode']=$dnsMode;
                        $this->db->run("UPDATE domains SET status='queued',hostname_status='pending',ssl_status='pending',cleanup_status='pending',last_error=NULL,replace_existing=?,applied_config=? WHERE id=?",[$replace?1:0,Validator::json($snapshot),$old['id']]);
                        $items[]=['hostname'=>$hostname,'domain_id'=>(int)$old['id'],'job_id'=>$newJob,'reused'=>false];
                        (new Audit($this->db))->write($actor,'domain.requeued','回滚后重新接入：'.$hostname,(int)$old['id'],$newJob);
                        $this->db->pdo->exec('RELEASE SAVEPOINT '.$savepoint);
                        continue;
                    }
                    $job = $this->db->one('SELECT * FROM jobs WHERE domain_id=? ORDER BY id DESC LIMIT 1', [$old['id']]);
                    $items[] = ['hostname'=>$hostname,'domain_id'=>(int)$old['id'],'job_id'=>(int)($job['id'] ?? 0),'reused'=>true];
                    $this->db->pdo->exec('RELEASE SAVEPOINT '.$savepoint);
                    continue;
                }
                $id = $this->db->insert('INSERT INTO domains(hostname,profile_id,dns_account_id,dns_zone_id,entry_id,origin_id,replace_existing) VALUES(?,?,?,?,?,?,?)', [$hostname,$profile['id'],$account['id'],$zone['cf_id'],$entry['id'],$origin['id'],$replace?1:0]);
                $snapshot=(new DomainConfig($this->db))->capture(['profile_id'=>$profile['id'],'entry_id'=>$entry['id'],'origin_id'=>$origin['id']],null,null,$check);
                $snapshot['dns_mode']=$dnsMode;
                $this->db->run('UPDATE domains SET applied_config=? WHERE id=?',[Validator::json($snapshot),$id]);
                $job = $this->queue($id, 'onboard', 'precheck');
                (new Audit($this->db))->write($actor, 'domain.queued', '已提交接入：' . $hostname, $id, $job);
                $items[] = ['hostname'=>$hostname,'domain_id'=>$id,'job_id'=>$job,'reused'=>false];
                $this->db->pdo->exec('RELEASE SAVEPOINT '.$savepoint);
            } catch (AppException $e) {
                $this->db->pdo->exec('ROLLBACK TO SAVEPOINT '.$savepoint);$this->db->pdo->exec('RELEASE SAVEPOINT '.$savepoint);
                $items[] = ['hostname'=>(string)$raw,'error'=>$e->getMessage()];
            } catch (\PDOException $e) {
                if (in_array($e->errorInfo[1]??0,[1205,1213],true)) { throw new AppException('并发请求发生锁冲突，请使用相同幂等键稍后重试',503,'concurrent_request'); }
                if (($e->errorInfo[1]??0)!==1062) { throw $e; }
                $this->db->pdo->exec('ROLLBACK TO SAVEPOINT '.$savepoint);$this->db->pdo->exec('RELEASE SAVEPOINT '.$savepoint);
                $existing=$this->db->one('SELECT * FROM domains WHERE hostname=? FOR UPDATE',[$hostname]);
                $same=$existing&&!(bool)$existing['deleted_at'];
                foreach(['profile_id','dns_account_id','entry_id','origin_id'] as $field){if($existing&&(int)$existing[$field]!==(int)$input[$field]){$same=false;}}
                if ($existing&&DnsMode::of((new DomainConfig($this->db))->applied($existing))!==$dnsMode) { $same=false; }
                if (!$same) { $items[]=['hostname'=>$hostname,'error'=>'域名由并发请求以其他配置创建，请检查'];continue; }
                $job=$this->db->one('SELECT id FROM jobs WHERE domain_id=? ORDER BY id DESC LIMIT 1',[$existing['id']]);
                $items[]=['hostname'=>$hostname,'domain_id'=>(int)$existing['id'],'job_id'=>(int)($job['id']??0),'reused'=>true];
            }
        }
        return ['items'=>$items];
    }
    public function queue(int $domain, string $kind, string $stage): int
    {
        $existing = $this->db->one("SELECT id FROM jobs WHERE domain_id=? AND status IN ('queued','waiting','paused','needs_attention','failed') ORDER BY id DESC LIMIT 1", [$domain]);
        if ($existing) { throw new AppException('该域名已有未完成任务，请先重试、恢复或回滚', 409); }
        return $this->db->insert('INSERT INTO jobs(domain_id,kind,stage) VALUES(?,?,?)', [$domain,$kind,$stage]);
    }
    public function detail(int $id): array
    {
        $domain = $this->db->one('SELECT * FROM domains WHERE id=?', [$id]);
        if (!$domain) { throw new AppException('域名不存在', 404); }
        $domain['jobs'] = $this->db->all('SELECT * FROM jobs WHERE domain_id=? ORDER BY id DESC LIMIT 30', [$id]);
        $domain['verification_records'] = $this->db->all('SELECT * FROM verification_records WHERE domain_id=?', [$id]);
        $domain['changes'] = $this->db->all('SELECT * FROM dns_changes WHERE domain_id=? ORDER BY id DESC LIMIT 100', [$id]);
        $domain['applied_config']=(new DomainConfig($this->db))->applied($domain);
        $domain['dns_mode']=DnsMode::of($domain['applied_config']);
        $domain['configuration_changes']=$this->db->all('SELECT * FROM domain_changes WHERE domain_id=? ORDER BY id DESC LIMIT 30',[$id]);
        $domain['managed_sources']=$this->db->all('SELECT hostname,source_ip,source_hostname,state,created_at FROM managed_sources WHERE domain_id=? ORDER BY id',[$id]);
        return $domain;
    }
    public function configure(int $id,array $input,string $actor):array
    {
        return $this->db->transaction(function()use($id,$input,$actor):array {
            $domain=$this->db->one('SELECT * FROM domains WHERE id=? AND deleted_at IS NULL FOR UPDATE',[$id]);
            if (!$domain) { throw new AppException('域名不存在',404); }
            if ($domain['status']!=='active') { throw new AppException('只有已接入的域名可以切换配置',409); }
            if (isset($input['expected_version'])&&(int)$input['expected_version']!==(int)$domain['config_version']) { throw new AppException('域名配置已更新，请刷新后重试',409,'version_conflict'); }
            $configs=new DomainConfig($this->db);$before=$configs->applied($domain);
            if (isset($input['type'])) {
                if (!in_array($input['type'],['A','AAAA','CNAME','preset'],true)) { throw new AppException('回源类型不正确'); }
                if ($input['type']==='preset') {
                    if (isset($input['source_ip'])||isset($input['source_hostname'])||empty($input['origin_id'])) { throw new AppException('预设模式请选择回源预设'); }
                } elseif($input['type']==='CNAME') {
                    if(!isset($input['source_hostname'])){throw new AppException('请填写 CNAME 回源域名');}
                } else {
                    $ip=Validator::publicIp($input['source_ip']??'');
                    if (($input['type']==='AAAA')!==str_contains($ip,':')) { throw new AppException('回源类型与源站 IP 不匹配'); }
                }
            }
            if(array_key_exists('source_ip',$input)&&array_key_exists('source_hostname',$input)){throw new AppException('回源 IP 和 CNAME 域名请选择一种');}
            if(array_key_exists('source_hostname',$input)){$input['origin_id']=!empty($before['origin']['external_dns'])&&Validator::hostname($input['source_hostname'])===($before['origin']['source_hostname']??null)?(int)$before['origin']['id']:(new ManagedOrigin($this->db))->selectHostname($before['profile'],$domain['hostname'],$input['source_hostname']);}
            if (array_key_exists('source_ip',$input)) { $input['origin_id']=!empty($before['origin']['external_dns'])&&Validator::publicIp($input['source_ip'])===($before['origin']['source_ip']??null)?(int)$before['origin']['id']:(new ManagedOrigin($this->db))->select($before['profile'],$domain['hostname'],$input['source_ip']); }
            $target=$configs->capture($domain,(int)($input['entry_id']??$domain['entry_id']),(int)($input['origin_id']??$domain['origin_id']),DomainConfig::checkOptions($input['check']??[],$before['check']));
            if (array_key_exists('dns_mode',$input)&&!array_intersect(['entry_id','origin_id','source_ip','source_hostname','check'],array_keys($input))) { $target=$before; }
            $target['dns_mode']=DnsMode::validate($input['dns_mode']??DnsMode::of($before));
            (new EntryAlias($this->db))->assertBusiness($domain['hostname'],$target);
            if (DnsMode::preferred($target)&&$domain['hostname']===$target['profile']['zone_name']) { throw new AppException('SaaS Zone 根域名不能使用优选接入'); }
            $configs->assertAvailable($target,(int)$domain['dns_account_id']);
            if (DomainConfig::equivalent($before,$target)) { return ['changed'=>false,'domain_id'=>$id,'config_version'=>(int)$domain['config_version']]; }
            $job=$this->queue($id,'change','change_precheck');
            $change=$this->db->insert('INSERT INTO domain_changes(domain_id,job_id,from_config,to_config) VALUES(?,?,?,?)',[$id,$job,Validator::json($before),Validator::json($target)]);
            $context=['change_id'=>$change,'replace_existing'=>Validator::boolean($input['replace_existing']??false),'preserve_dns_until_ready'=>Validator::boolean($input['preserve_dns_until_ready']??false)];
            if(isset($input['approved_dns'])){if(!is_array($input['approved_dns'])){throw new AppException('DNS 确认基线格式不正确');}$context['approved_dns']=$input['approved_dns'];}
            $this->db->run('UPDATE jobs SET context_json=? WHERE id=?',[Validator::json($context),$job]);
            $this->db->run("UPDATE domains SET status='changing',last_error=NULL WHERE id=?",[$id]);
            (new Audit($this->db))->write($actor,'domain.change_queued','切换 DNS 模式/入口/回源：'.$target['dns_mode'].'，变更 #'.$change,$id,$job);
            return ['changed'=>true,'domain_id'=>$id,'job_id'=>$job,'change_id'=>$change];
        });
    }
    public function action(int $id, string $action, string $actor): array
    {
        return $this->db->transaction(function () use ($id,$action,$actor): array {
            $domain = $this->db->one('SELECT * FROM domains WHERE id=? FOR UPDATE', [$id]);
            if (!$domain||$domain['deleted_at']) { throw new AppException('域名不存在或已移除管理', 404); }
            $job = $this->db->one('SELECT * FROM jobs WHERE domain_id=? ORDER BY id DESC LIMIT 1', [$id]);
            if (!$job) { throw new AppException('任务不存在', 404); }
            if ($action === 'pause') {
                if (!in_array($job['status'], ['queued','waiting'], true)) { throw new AppException('当前任务不能暂停', 409); }
                $this->db->run("UPDATE jobs SET status='paused' WHERE id=?", [$job['id']]);
            } elseif ($action==='cancel') {
                if (in_array($job['status'],['completed','cancelled'],true)) { throw new AppException('任务已经结束',409); }
                $this->db->run("UPDATE jobs SET status='cancelled',last_error='管理员取消；已执行变更保留，可回滚' WHERE id=?",[$job['id']]);
                $this->db->run("UPDATE domains SET status='needs_attention',last_error='任务已取消，已执行变更保留，可回滚' WHERE id=?",[$id]);
                $this->db->run("UPDATE domain_changes SET status='failed',last_error='任务已取消' WHERE job_id=? AND status NOT IN ('applied','rolled_back')",[$job['id']]);
            } elseif (in_array($action, ['retry','resume'], true)) {
                if (!in_array($job['status'], ['paused','needs_attention','failed'], true)) { throw new AppException('当前任务不需要恢复', 409); }
                $this->db->run("UPDATE jobs SET status='queued',attempts=0,last_error=NULL,run_at=UTC_TIMESTAMP() WHERE id=?", [$job['id']]);
            } elseif ($action === 'rollback') {
                if(!empty($domain['adopted'])&&!$this->db->value('SELECT id FROM domain_changes WHERE domain_id=?',[$id])){throw new AppException('纳管尚未改动云端，无接入前解析可回滚；可选择仅移除面板管理',409);}
                if ($job['kind'] === 'rollback' && $job['status'] === 'completed') { throw new AppException('任务已经回滚', 409); }
                $this->db->run("UPDATE jobs SET status='cancelled' WHERE domain_id=? AND status NOT IN ('completed','cancelled')", [$id]);
                $change=$this->db->one("SELECT * FROM domain_changes WHERE domain_id=? AND status IN ('queued','applying','failed','applied','restoring','rollback_failed') ORDER BY id DESC LIMIT 1",[$id]);
                if ($change) {
                    $before=(new DomainConfig($this->db))->applied($domain);
                    if ($change['status']==='applied'&&!DomainConfig::equivalent($before,json_decode($change['to_config'],true))) { throw new AppException('该变更不是当前生效配置，不能跨版本回滚',409); }
                    $job['id']=$this->queue($id,'change_rollback','change_restore');
                    $this->db->run('UPDATE jobs SET context_json=? WHERE id=?',[Validator::json(['change_id'=>(int)$change['id'],'restore_source_job'=>(int)$change['job_id'],'automatic'=>false]),$job['id']]);
                    $this->db->run("UPDATE domain_changes SET status='restoring' WHERE id=?",[$change['id']]);
                    $this->db->run("UPDATE domains SET status='changing' WHERE id=?",[$id]);
                } else { $job['id'] = $this->queue($id,'rollback','rollback'); }
            } elseif ($action === 'diagnose') {
                $job['id'] = $this->queue($id,'diagnose','diagnose');
            } else { throw new AppException('不支持的操作', 404); }
            (new Audit($this->db))->write($actor, 'domain.' . $action, '提交操作：' . $action, $id, (int)$job['id']);
            return ['job_id'=>(int)$job['id']];
        });
    }
    public function remove(int $id,array $input,string $actor):array
    {
        return $this->db->transaction(function()use($id,$input,$actor):array {
            $domain=$this->db->one('SELECT * FROM domains WHERE id=? AND deleted_at IS NULL FOR UPDATE',[$id]);
            if (!$domain) { throw new AppException('域名不存在或已移除管理',404); }
            if (isset($input['expected_version'])&&(int)$input['expected_version']!==(int)$domain['config_version']) { throw new AppException('记录版本已更新，请刷新后再删除',409,'version_conflict'); }
            $mode=(string)($input['mode']??'');
            if (!in_array($mode,['delete_records','forget','restore','delete_remote'],true)) { throw new AppException('移除方式必须是 delete_records、forget、restore 或 delete_remote'); }
            if ($mode==='forget') {
                $this->db->run("UPDATE jobs SET status='cancelled' WHERE domain_id=? AND status NOT IN ('completed','cancelled')",[$id]);
                $this->db->run("UPDATE domain_changes SET status='abandoned',last_error='已移除面板管理，云端变更保留' WHERE domain_id=? AND status IN ('queued','applying','failed','restoring','rollback_failed')",[$id]);
                $this->db->run("UPDATE domains SET deleted_at=UTC_TIMESTAMP(),status='unmanaged',next_check_at=NULL WHERE id=?",[$id]);
                (new Audit($this->db))->write($actor,'domain.unmanaged','仅移除面板管理，DNS 和云端主机名保留',$id);
                return ['domain_id'=>$id,'removed'=>true,'mode'=>$mode];
            }
            $job=$this->queue($id,'remove','remove');
            $this->db->run('UPDATE jobs SET context_json=? WHERE id=?',[Validator::json(['mode'=>$mode]),$job]);
            $this->db->run("UPDATE domains SET status='removing' WHERE id=?",[$id]);
            (new Audit($this->db))->write($actor,'domain.remove_queued','提交移除任务：'.$mode,$id,$job);
            return ['domain_id'=>$id,'job_id'=>$job,'mode'=>$mode];
        });
    }
}

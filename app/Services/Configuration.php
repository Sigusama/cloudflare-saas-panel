<?php
// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);
namespace Panel\Services;

use Panel\Core\{Database, Crypto, Validator, AppException};

final class Configuration
{
    public function __construct(private Database $db, private Crypto $crypto, private Cloud $cloud) {}
    public function list(): array
    {
        return [
            'accounts' => $this->withUsage('accounts',$this->db->all('SELECT id,name,provider,auth_type,active,checked_at FROM accounts WHERE deleted_at IS NULL ORDER BY id')),
            'zones' => $this->db->all('SELECT z.* FROM zones z JOIN accounts a ON a.id=z.account_id WHERE a.deleted_at IS NULL ORDER BY z.name'),
            'profiles' => $this->withUsage('profiles',$this->db->all('SELECT * FROM profiles WHERE deleted_at IS NULL ORDER BY id')),
            'entries' => $this->withUsage('entries',array_map(static function(array $row): array { $row['targets'] = json_decode($row['targets'], true); return $row; }, $this->db->all('SELECT * FROM entries WHERE deleted_at IS NULL ORDER BY id'))),
            'origins' => $this->withUsage('origins',$this->db->all('SELECT * FROM origins WHERE deleted_at IS NULL AND source_ip IS NULL AND source_hostname IS NULL AND external_dns_json IS NULL ORDER BY id')),
            'version'=>\Panel\Core\Version::current(),
            'demo'=>$this->db->value("SELECT value FROM settings WHERE name='preview_mode'")==='1',
            'defaults'=>(new Settings($this->db))->read(),
            'worker_heartbeat' => $this->db->value("SELECT value FROM settings WHERE name='worker_heartbeat'") ?: null,
        ];
    }
    public function authentication(int $id):array
    {
        $account=$this->db->one('SELECT auth_type,token_encrypted FROM accounts WHERE id=? AND deleted_at IS NULL',[$id]);
        if (!$account) { throw new AppException('账号不存在或已删除',404); }
        $credential=CloudflareCredential::stored($account['auth_type'],$this->crypto->decrypt($account['token_encrypted']));
        return ['auth_type'=>$credential['auth_type'],'email'=>$credential['email']];
    }
    public function save(string $resource, array $input, string $actor): int
    {
        return $this->db->transaction(fn():int=>$this->create($resource,$input,$actor));
    }
    private function create(string $resource,array $input,string $actor):int
    {
        $name = Validator::label($input['name'] ?? '');
        switch ($resource) {
            case 'accounts':
                $credential = CloudflareCredential::input($input);
                $id = $this->db->insert('INSERT INTO accounts(name,auth_type,token_encrypted) VALUES(?,?,?)', [$name, $credential['auth_type'], $this->crypto->encrypt(CloudflareCredential::pack($credential))]);
                break;
            case 'profiles':
                $account = (int)($input['account_id'] ?? 0);
                $zone = $this->db->one('SELECT * FROM zones WHERE account_id=? AND cf_id=? FOR UPDATE', [$account, $input['zone_id'] ?? '']);
                if (!$zone) { throw new AppException('请先同步账号 Zone，再选择 SaaS Zone'); }
                if ($this->db->value('SELECT id FROM profiles WHERE account_id=? AND zone_id=? AND deleted_at IS NULL',[$account,$zone['cf_id']])) { throw new AppException('该账号的 SaaS Zone 已有配置，请编辑现有配置或增加回源预设',409); }
                $cname = Validator::hostname((string)($input['cname_target'] ?? ''));
                $fallback = Validator::hostname((string)($input['fallback_origin'] ?? ''));
                if (!Validator::zoneMatches($cname, $zone['name']) || !Validator::zoneMatches($fallback, $zone['name']) || $cname === $fallback) { throw new AppException('标准接入目标与默认回源需为 SaaS Zone 下不同的主机名'); }
                $this->assertNotEntry([$cname,$fallback]);
                $id = $this->db->insert('INSERT INTO profiles(name,account_id,zone_id,zone_name,cname_target,fallback_origin) VALUES(?,?,?,?,?,?)', [$name, $account, $zone['cf_id'], $zone['name'], $cname, $fallback]);
                $this->db->run("INSERT INTO origins(name,profile_id,mode,hostname) VALUES(?,?,'fallback',?)",[mb_substr($name.' · 默认回源',0,100),$id,$fallback]);
                if (!(int)$this->db->value('SELECT COUNT(*) FROM entries')) { $this->db->run("INSERT INTO entries(name,kind,targets) VALUES('SaaS 标准接入','standard','[]')"); }
                break;
            case 'entries':
                $kind = (string)($input['kind'] ?? '');
                if (!in_array($kind, ['standard', 'cname', 'ip'], true)) { throw new AppException('入口类型不正确'); }
                $targets = is_array($input['targets'] ?? null) ? $input['targets'] : preg_split('/[\s,]+/', trim((string)($input['targets'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
                $targets = $kind === 'standard' ? [] : array_values(array_unique(array_map($kind === 'ip' ? Validator::publicIp(...) : Validator::hostname(...), $targets)));
                if (($kind === 'cname' && count($targets) !== 1) || ($kind === 'ip' && (count($targets) < 1 || count($targets) > 10))) { throw new AppException('CNAME 入口填写一个域名，IP 入口填写 1 至 10 个 IP'); }
                $alias=(new EntryAlias($this->db))->fields($input,$kind,$targets);
                try{$id = $this->db->insert('INSERT INTO entries(name,kind,targets,zone_ref,hostname) VALUES(?,?,?,?,?)', [$name, $kind, Validator::json($targets),$alias['zone_ref'],$alias['hostname']]);}
                catch(\PDOException $e){if(($e->errorInfo[1]??0)===1062){throw new AppException('入口域名已被另一配置使用',409);}throw $e;}
                break;
            case 'origins':
                $profile = $this->db->one('SELECT * FROM profiles WHERE id=? AND active=1 AND deleted_at IS NULL', [(int)($input['profile_id'] ?? 0)]);
                $mode = (string)($input['mode'] ?? '');
                if (!$profile || !in_array($mode, ['fallback', 'custom'], true)) { throw new AppException('请选择有效 SaaS 配置和回源模式'); }
                $hostname = $mode === 'custom' ? Validator::hostname((string)($input['hostname'] ?? '')) : $profile['fallback_origin'];
                $this->assertNotEntry([$hostname]);
                $id = $this->db->insert('INSERT INTO origins(name,profile_id,mode,hostname) VALUES(?,?,?,?)', [$name, $profile['id'], $mode, $hostname]);
                break;
            default: throw new AppException('配置类型不存在', 404);
        }
        (new Audit($this->db))->write($actor, 'config.created', $resource . ' #' . $id . ' ' . $name);
        return $id;
    }
    private function table(string $resource):string
    {
        if (!in_array($resource,['accounts','profiles','entries','origins'],true)) { throw new AppException('配置类型不存在',404); }
        return $resource;
    }
    private function assertNotEntry(array $names):void
    {
        foreach($names as $name){if($this->db->value('SELECT id FROM entries WHERE hostname=?',[$name])){throw new AppException('该名称已保留为优选入口，不能同时用作 SaaS 接入或回源',409);}}
    }
    private function withUsage(string $resource,array $rows):array
    {
        foreach ($rows as &$row) { $row['references']=$this->references($resource,(int)$row['id']);if($resource==='entries'){$row['route_state']=$this->db->value('SELECT state FROM entry_routes WHERE entry_id=?',[$row['id']])?:null;} }
        return $rows;
    }
    public function references(string $resource,int $id):int
    {
        $this->table($resource);
        $field=['accounts'=>'dns_account_id','profiles'=>'profile_id','entries'=>'entry_id','origins'=>'origin_id'][$resource];
        $count=(int)$this->db->value('SELECT COUNT(*) FROM domains WHERE '.$field.'=? AND deleted_at IS NULL',[$id]);
        if ($resource==='accounts') { $count+=(int)$this->db->value('SELECT COUNT(*) FROM profiles WHERE account_id=? AND deleted_at IS NULL',[$id]); }
        if($resource==='accounts'){$count+=(int)$this->db->value('SELECT COUNT(*) FROM entries e JOIN zones z ON z.id=e.zone_ref WHERE z.account_id=? AND e.deleted_at IS NULL',[$id]);}
        if ($resource==='profiles') { $count+=(int)$this->db->value('SELECT COUNT(*) FROM origins WHERE profile_id=? AND deleted_at IS NULL',[$id]); }
        if (in_array($resource,['entries','origins'],true)) {
            $path=$resource==='entries'?'$.entry.id':'$.origin.id';
            $count+=(int)$this->db->value("SELECT COUNT(*) FROM domain_changes WHERE status IN ('queued','applying','restoring','rollback_failed') AND CAST(JSON_UNQUOTE(JSON_EXTRACT(to_config,?)) AS UNSIGNED)=?",[$path,$id]);
        }
        return $count;
    }
    public function update(string $resource,int $id,array $input,string $actor):array
    {
        $this->table($resource);
        return $this->db->operationalLock(fn():array=>$this->db->transaction(function()use($resource,$id,$input,$actor):array {
            $old=$this->db->one('SELECT * FROM '.$resource.' WHERE id=? AND deleted_at IS NULL FOR UPDATE',[$id]);
            if (!$old) { throw new AppException('配置不存在或已删除',404); }
            if ($resource==='origins'&&(!empty($old['source_ip'])||!empty($old['source_hostname'])||!empty($old['external_dns_json']))) { throw new AppException('自动 / 纳管回源请在所属域名记录中编辑',409); }
            $data=['name'=>array_key_exists('name',$input)?Validator::label($input['name']):$old['name'],'active'=>array_key_exists('active',$input)?(Validator::boolean($input['active'])?1:0):(int)$old['active']];
            if ($resource==='accounts'&&array_intersect(['auth_type','token','email','global_key'],array_keys($input))) {
                $previous=CloudflareCredential::stored($old['auth_type'],$this->crypto->decrypt($old['token_encrypted']));
                $credential=CloudflareCredential::input($input,$previous);
                if ($credential!==$previous) { $data['auth_type']=$credential['auth_type'];$data['token_encrypted']=$this->crypto->encrypt(CloudflareCredential::pack($credential));$data['checked_at']=null; }
            }
            if ($resource==='entries') {
                $kind=(string)($input['kind']??$old['kind']);
                if (!in_array($kind,['standard','cname','ip'],true)) { throw new AppException('入口类型不正确'); }
                $targets=$input['targets']??json_decode($old['targets'],true);
                if (!is_array($targets)) { $targets=preg_split('/[\s,]+/',trim((string)$targets),-1,PREG_SPLIT_NO_EMPTY); }
                $targets=$kind==='standard'?[]:array_values(array_unique(array_map($kind==='ip'?Validator::publicIp(...):Validator::hostname(...),$targets)));
                if (($kind==='cname'&&count($targets)!==1)||($kind==='ip'&&(count($targets)<1||count($targets)>10))) { throw new AppException('CNAME 填一个域名，IP 入口填写 1 至 10 个 IP'); }
                $data+=['kind'=>$kind,'targets'=>Validator::json($targets)];
                $validateAlias=array_intersect(['zone_id','zone_ref','record_name','hostname'],array_keys($input))||$kind!==$old['kind']||$data['targets']!==$old['targets'];
                $data+=$validateAlias?(new EntryAlias($this->db))->fields($input,$kind,$targets,$old):['zone_ref'=>$old['zone_ref'],'hostname'=>$old['hostname']];
                $aliasChanged=$data['hostname']!==$old['hostname']||(string)$data['zone_ref']!==(string)$old['zone_ref']||$kind!==$old['kind']||$data['targets']!==$old['targets'];
                if($aliasChanged&&(!empty($old['hostname'])||!empty($data['hostname']))&&($this->references('entries',$id)>0||$this->db->value('SELECT entry_id FROM entry_routes WHERE entry_id=?',[$id]))){throw new AppException('共享入口已被引用或开始部署，修改解析会影响多条记录；请新建入口后逐条切换',409,'entry_in_use');}
            }
            if ($resource==='profiles') {
                $account=(int)($input['account_id']??$old['account_id']);$zoneId=(string)($input['zone_id']??$old['zone_id']);
                $zone=$this->db->one('SELECT * FROM zones WHERE account_id=? AND cf_id=?',[$account,$zoneId]);
                if (!$zone) { throw new AppException('请选择已同步的 SaaS Zone'); }
                $cnameInput=(string)($input['cname_target']??$old['cname_target']);
                $data+=['account_id'=>$account,'zone_id'=>$zoneId,'zone_name'=>$zone['name'],'cname_target'=>$old['cname_target']===''&&trim($cnameInput)===''?'':Validator::hostname($cnameInput),'fallback_origin'=>Validator::hostname((string)($input['fallback_origin']??$old['fallback_origin']))];
                if (($data['cname_target']!==''&&!Validator::zoneMatches($data['cname_target'],$zone['name']))||!Validator::zoneMatches($data['fallback_origin'],$zone['name'])||$data['cname_target']===$data['fallback_origin']) { throw new AppException('标准入口与默认回源必须是 SaaS Zone 下不同的主机名'); }
                $this->assertNotEntry([$data['cname_target'],$data['fallback_origin']]);
                foreach (['account_id','zone_id','cname_target','fallback_origin'] as $field) {
                    if ((string)$data[$field]!== (string)$old[$field]&&!($field==='cname_target'&&$old['cname_target']==='')&&(int)$this->db->value('SELECT COUNT(*) FROM domains WHERE profile_id=?',[$id])) { throw new AppException('SaaS 配置已有域名或历史引用，账号、Zone 和共享回源需新建配置后迁移，避免影响其他域名',409); }
                }
                if ($this->db->value('SELECT id FROM profiles WHERE account_id=? AND zone_id=? AND id<>? AND deleted_at IS NULL',[$account,$zoneId,$id])) { throw new AppException('该 SaaS Zone 已有其他配置',409); }
            }
            if ($resource==='origins') {
                $profileId=(int)($input['profile_id']??$old['profile_id']);$profile=$this->db->one('SELECT * FROM profiles WHERE id=? AND deleted_at IS NULL',[$profileId]);
                if (!$profile) { throw new AppException('SaaS 配置不存在'); }
                if ($profileId!==(int)$old['profile_id']&&$this->references($resource,$id)>0) { throw new AppException('已使用的回源不能更换所属 SaaS，请新建回源',409); }
                $mode=(string)($input['mode']??$old['mode']);
                if (!in_array($mode,['fallback','custom'],true)) { throw new AppException('回源模式不正确'); }
                $data+=['profile_id'=>$profileId,'mode'=>$mode,'hostname'=>$mode==='fallback'?$profile['fallback_origin']:Validator::hostname((string)($input['hostname']??$old['hostname']))];
                $this->assertNotEntry([$data['hostname']]);
            }
            $assign=implode(',',array_map(static fn($field):string=>$field.'=?',array_keys($data)));
            $this->db->run('UPDATE '.$resource.' SET '.$assign.' WHERE id=?',[...array_values($data),$id]);
            if ($resource==='profiles'&&$data['fallback_origin']!==$old['fallback_origin']) { $this->db->run("UPDATE origins SET hostname=? WHERE profile_id=? AND mode='fallback'",[$data['fallback_origin'],$id]); }
            if (!$data['active']) { (new Settings($this->db))->clearReference($resource,$id); }
            $this->pauseUnavailableJobs();
            $publicChanges=$data;unset($publicChanges['token_encrypted'],$publicChanges['checked_at']);
            (new Audit($this->db))->write($actor,isset($data['token_encrypted'])?'credential.rotated':'config.updated',$resource.' #'.$id.' 更新字段：'.Validator::json($publicChanges).'；已接入域名保留生效快照');
            return ['id'=>$id,'references'=>$this->references($resource,$id),'message'=>'配置已保存，已有域名不会自动切换'];
        }));
    }
    private function pauseUnavailableJobs():void
    {
        $rows=$this->db->all("SELECT j.id,j.domain_id,d.dns_account_id,d.applied_config,j.context_json FROM jobs j JOIN domains d ON d.id=j.domain_id WHERE j.status IN ('queued','waiting') AND j.kind NOT IN ('rollback','change_rollback','remove') AND j.stage<>'change_restore'");
        foreach ($rows as $row) {
            $config=json_decode($row['applied_config']??'{}',true);
            $context=json_decode($row['context_json']??'{}',true);
            if (!empty($context['change_id'])) { $config=json_decode($this->db->value('SELECT to_config FROM domain_changes WHERE id=?',[$context['change_id']])?:'{}',true); }
            if (!$config) { continue; }
            try { (new DomainConfig($this->db))->assertAvailable($config,(int)$row['dns_account_id']); }
            catch (AppException $e) { $this->db->run("UPDATE jobs SET status='paused',last_error=? WHERE id=?",[$e->getMessage(),$row['id']]);(new Audit($this->db))->write('system','job.paused',$e->getMessage(),(int)$row['domain_id'],(int)$row['id']); }
        }
    }
    public function delete(string $resource,int $id,string $actor):array
    {
        $this->table($resource);
        return $this->db->operationalLock(fn():array=>$this->db->transaction(function()use($resource,$id,$actor):array {
            $old=$this->db->one('SELECT * FROM '.$resource.' WHERE id=? AND deleted_at IS NULL FOR UPDATE',[$id]);
            if (!$old) { throw new AppException('配置不存在或已删除',404); }
            if ($resource==='origins'&&empty($old['external_dns_json'])&&(!empty($old['source_ip'])||!empty($old['source_hostname']))) { throw new AppException('自动回源由所属域名的接入和移除任务管理',409); }
            $references=$this->references($resource,$id);
            if ($references>0) { throw new AppException('配置仍有 '.$references.' 处引用，请先切换域名、处理任务或删除依赖配置',409,'config_in_use'); }
            $this->db->run('UPDATE '.$resource.' SET active=0,deleted_at=UTC_TIMESTAMP() WHERE id=?',[$id]);
            if ($resource==='accounts') { $this->db->run("UPDATE accounts SET token_encrypted='',checked_at=NULL WHERE id=?",[$id]); }
            (new Settings($this->db))->clearReference($resource,$id);
            (new Audit($this->db))->write($actor,'config.deleted','移除配置 '.$resource.' #'.$id.'，云端资源不变，历史记录保留');
            return ['deleted'=>true];
        }));
    }
    public function sync(int $account): array
    {
        if($this->db->value("SELECT value FROM settings WHERE name='preview_mode'")==='1'){
            if(!$this->db->value('SELECT id FROM accounts WHERE id=? AND active=1 AND deleted_at IS NULL',[$account])){throw new AppException('Cloudflare 账号不存在或已停用');}
            return ['zones'=>(int)$this->db->value("SELECT COUNT(*) FROM zones WHERE account_id=? AND status='active'",[$account]),'message'=>'演示模式：使用本地示例 Zone，未连接 Cloudflare'];
        }
        $zones = $this->cloud->dns($account)->zones();
        $this->db->run("UPDATE zones SET status='unavailable' WHERE account_id=?",[$account]);
        foreach ($zones as $zone) {
            $this->db->run('INSERT INTO zones(account_id,cf_id,name,status) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name),status=VALUES(status)', [$account, $zone['id'], $zone['name'], $zone['status']]);
        }
        $this->db->run('UPDATE accounts SET checked_at=UTC_TIMESTAMP() WHERE id=?', [$account]);
        return ['zones' => count($zones), 'message' => '已读取授权 Zone；写权限将在实际操作时分别验证'];
    }
    public function profileCheck(int $id, bool $setup, string $actor): array
    {
        $profile = $this->db->one('SELECT * FROM profiles WHERE id=? AND active=1', [$id]);
        if (!$profile) { throw new AppException('SaaS 配置不存在', 404); }
        if($setup&&empty($profile['cname_target'])){throw new AppException('此站点按已有优选配置纳管，未设置用于新建接入的标准 CNAME；不会补建或替换原解析');}
        if($this->db->value("SELECT value FROM settings WHERE name='preview_mode'")==='1'){return ['fallback'=>['origin'=>$profile['fallback_origin'],'status'=>'demo'],'message'=>'演示模式：展示本地服务配置，云端未执行'];}
        $dns = $this->cloud->dns((int)$profile['account_id']);
        $fallback = $dns->records($profile['zone_id'], $profile['fallback_origin']);
        if (!array_filter($fallback, static fn($r): bool => in_array($r['type'], ['A','AAAA','CNAME'], true) && !empty($r['proxied']))) { throw new AppException('请先在 SaaS Zone 创建指向真实源站的橙云默认回源记录：' . $profile['fallback_origin']); }
        $client = $this->cloud->client((int)$profile['account_id']);
        if ($setup) {
            $existing = $dns->records($profile['zone_id'], $profile['cname_target']);
            if ($existing === []) {
                $dns->create($profile['zone_id'], ['type'=>'CNAME','name'=>$profile['cname_target'],'content'=>$profile['fallback_origin'],'proxied'=>true,'ttl'=>1]);
            } elseif (!array_filter($existing, static fn($r): bool => $r['type']==='CNAME' && rtrim($r['content'], '.')===$profile['fallback_origin'] && !empty($r['proxied']))) {
                throw new AppException('标准接入目标存在其他记录，请先检查，不会自动覆盖');
            }
            $result = $client->request('PUT', '/zones/' . $profile['zone_id'] . '/custom_hostnames/fallback_origin', ['origin'=>$profile['fallback_origin']])['result'];
            (new Audit($this->db))->write($actor, 'profile.setup', '设置 SaaS 默认回源与标准入口：' . $profile['name']);
        } else {
            $result = $client->request('GET', '/zones/' . $profile['zone_id'] . '/custom_hostnames/fallback_origin')['result'];
        }
        return ['fallback' => $result, 'message' => '请确认 origin 与面板配置一致且 status 为 active'];
    }
}

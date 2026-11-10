<?php
// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);
namespace Panel\Services;

use Panel\Core\{Database,Validator,AppException,Version};

/** Portable configuration and onboarding plans, deliberately excluding credentials and runtime state. */
final class Transfer
{
    public function __construct(private Database $db,private Configuration $configuration) {}

    public function export(?int $zoneId=null): array
    {
        $config=$this->configuration->list();
        $document=['format'=>'cf-saas-panel','format_version'=>1,'app_version'=>Version::current(),'exported_at'=>gmdate('c'),'scope'=>$zoneId===null?'all':'zone',
            'accounts'=>[],'profiles'=>[],'entries'=>[],'origins'=>[],'defaults'=>$config['defaults'],'records'=>[]];
        foreach ($config['accounts'] as $row) { $document['accounts'][]=['ref'=>(string)$row['id'],'name'=>$row['name'],'provider'=>$row['provider']]; }
        foreach ($config['profiles'] as $row) { $document['profiles'][]=['ref'=>(string)$row['id'],'name'=>$row['name'],'account_ref'=>(string)$row['account_id'],'zone_name'=>$row['zone_name'],'cname_target'=>$row['cname_target'],'fallback_origin'=>$row['fallback_origin'],'active'=>(bool)$row['active']]; }
        foreach ($config['entries'] as $row) { $portable=['ref'=>(string)$row['id'],'name'=>$row['name'],'kind'=>$row['kind'],'targets'=>$row['targets'],'active'=>(bool)$row['active']];$snapshot=(new EntryAlias($this->db))->capture($row);if(!empty($snapshot['hostname'])){$portable['alias']=['hostname'=>$snapshot['hostname'],'zone_name'=>$snapshot['zone_name'],'account_ref'=>(string)$snapshot['dns_account_id']];}$document['entries'][]=$portable; }
        foreach ($config['origins'] as $row) { $document['origins'][]=['ref'=>(string)$row['id'],'name'=>$row['name'],'profile_ref'=>(string)$row['profile_id'],'mode'=>$row['mode'],'hostname'=>$row['hostname'],'active'=>(bool)$row['active']]; }
        $params=[];$where='deleted_at IS NULL';
        if ($zoneId!==null) { $zone=(new ZoneRecords($this->db))->zone($zoneId);$where.=' AND dns_account_id=? AND dns_zone_id=?';$params=[$zone['account_id'],$zone['cf_id']]; }
        $rows=$this->db->all('SELECT * FROM domains WHERE '.$where.' ORDER BY id LIMIT 1001',$params);
        if (count($rows)>1000) { throw new AppException('单次最多导出 1000 条记录，请按主域名分别导出'); }
        foreach ($rows as $row) {
            $applied=(new DomainConfig($this->db))->applied($row);
            $entry=$applied['entry'];$origin=$applied['origin'];
            // Preserve each domain's applied snapshot even when the reusable preset was edited later.
            $entryRef='record-entry-'.$row['id'];
            $document['entries'][]=['ref'=>$entryRef,'name'=>$entry['name'],'kind'=>$entry['kind'],'targets'=>json_decode($entry['targets'],true),'active'=>true];
            if(!empty($entry['hostname'])){$document['entries'][array_key_last($document['entries'])]['alias']=['hostname'=>$entry['hostname'],'zone_name'=>$entry['zone_name'],'account_ref'=>(string)$entry['dns_account_id']];}
            $record=['hostname'=>$row['hostname'],'dns_account_ref'=>(string)$row['dns_account_id'],'profile_ref'=>(string)$row['profile_id'],'entry_ref'=>$entryRef,'check'=>$applied['check'],'dns_mode'=>DnsMode::of($applied)];
            if (!empty($origin['source_ip'])) { $record['source_ip']=$origin['source_ip']; }
            elseif(!empty($origin['source_hostname'])) { $record['source_hostname']=$origin['source_hostname']; }
            else {
                $originRef='record-origin-'.$row['id'];
                $document['origins'][]=['ref'=>$originRef,'name'=>$origin['name'],'profile_ref'=>(string)$row['profile_id'],'mode'=>$origin['mode'],'hostname'=>$origin['hostname'],'active'=>true];
                $record['origin_ref']=$originRef;
            }
            $document['records'][]=$record;
        }
        if (array_filter($document['records'],static fn($r):bool=>$r['dns_mode']!=='preferred')) { $document['format_version']=2; }
        if(array_filter($document['records'],static fn($r):bool=>isset($r['source_hostname']))){$document['format_version']=3;}
        if ($zoneId!==null) {
            $needed=['accounts'=>[(string)$zone['account_id']=>true],'profiles'=>[],'entries'=>[],'origins'=>[]];
            foreach ($document['records'] as $record) {
                $needed['accounts'][$record['dns_account_ref']]=true;$needed['profiles'][$record['profile_ref']]=true;$needed['entries'][$record['entry_ref']]=true;
                if (isset($record['origin_ref'])) { $needed['origins'][$record['origin_ref']]=true; }
            }
            foreach ($document['profiles'] as $profile) { if (isset($needed['profiles'][$profile['ref']])) { $needed['accounts'][$profile['account_ref']]=true; } }
            foreach($document['entries'] as $entry){if(isset($needed['entries'][$entry['ref']])&&isset($entry['alias'])){$needed['accounts'][$entry['alias']['account_ref']]=true;}}
            foreach ($needed as $section=>$refs) { $document[$section]=array_values(array_filter($document[$section],static fn($r):bool=>isset($refs[$r['ref']]))); }
            $document['defaults']=null;$document['zone_name']=$zone['name'];
        }
        if(array_filter($document['entries'],static fn($entry):bool=>isset($entry['alias']))){$document['format_version']=4;}
        if(array_filter($document['profiles'],static fn($profile):bool=>$profile['cname_target']==='')){$document['format_version']=5;}
        foreach (['accounts','profiles','entries','origins'] as $section) { if (count($document[$section])>2000) { throw new AppException('配置项过多，请按主域名分别导出'); } }
        if (strlen(Validator::json($document))>950000) { throw new AppException('导出内容超过 950 KB，请按主域名分批导出'); }
        return $document;
    }

    public function import(array $input,string $actor):array
    {
        $document=$input['document']??null;$bindings=$input['account_bindings']??[];
        if (!is_array($document)||($document['format']??'')!=='cf-saas-panel'||!in_array($document['format_version']??null,[1,2,3,4,5],true)) { throw new AppException('导入文件格式不正确，当前支持 cf-saas-panel 格式版本 1 / 2 / 3 / 4 / 5'); }
        if (!is_array($bindings)) { throw new AppException('账号绑定格式不正确'); }
        foreach (['accounts','profiles','entries','origins','records'] as $section) {
            if (!isset($document[$section])||!is_array($document[$section])||!array_is_list($document[$section])||count($document[$section])>2000) { throw new AppException('导入分组格式不正确或超过 2000 项：'.$section); }
        }
        if (count($document['records'])>1000) { throw new AppException('每次最多导入 1000 条记录'); }
        $preview=Validator::boolean($input['preview']??true);
        $applyDefaults=Validator::boolean($input['apply_defaults']??false);
        if ($applyDefaults&&!is_array($document['defaults']??null)) { throw new AppException('此文件不包含全局默认配置，请取消导入默认配置'); }
        try {
            return $this->db->transaction(function()use($document,$bindings,$actor,$preview,$applyDefaults):array {
                $result=$this->apply($document,$bindings,$actor,$applyDefaults);
                if ($preview) { throw new TransferPreview($result); }
                (new Audit($this->db))->write($actor,'config.imported','导入配置与接入计划：'.count($document['records']).' 条记录');
                return $result+['preview'=>false];
            });
        } catch (TransferPreview $result) { return $result->result+['preview'=>true]; }
    }

    private function reference(array $row,array &$seen):string
    {
        $ref=$row['ref']??null;
        if ((!is_string($ref)&&!is_int($ref))||!preg_match('/^[A-Za-z0-9_-]{1,80}$/D',(string)$ref)||isset($seen[(string)$ref])) { throw new AppException('导入引用缺失、重复或格式不正确'); }
        $seen[(string)$ref]=true;return (string)$ref;
    }
    private function mapped(array $map,mixed $ref,string $label):int
    {
        if ((!is_string($ref)&&!is_int($ref))||!isset($map[(string)$ref])) { throw new AppException('导入引用不存在：'.$label); }
        return $map[(string)$ref];
    }
    private function apply(array $doc,array $bindings,string $actor,bool $applyDefaults):array
    {
        $accounts=[];$profiles=[];$entries=[];$origins=[];$created=['profiles'=>0,'entries'=>0,'origins'=>0];
        $seen=[];
        foreach ($doc['accounts'] as $row) {
            if (!is_array($row)) { throw new AppException('账号条目格式不正确'); }
            $ref=$this->reference($row,$seen);
            if (($row['provider']??'')!=='cloudflare') { throw new AppException('当前仅支持 Cloudflare 账号'); }
            $name=Validator::label($row['name']??'');
            if (isset($bindings[$ref])) { $matches=$this->db->all('SELECT id FROM accounts WHERE id=? AND provider=? AND active=1 AND deleted_at IS NULL',[(int)$bindings[$ref],'cloudflare']); }
            else { $matches=$this->db->all("SELECT id FROM accounts WHERE name=? AND provider='cloudflare' AND active=1 AND deleted_at IS NULL",[$name]); }
            if (count($matches)!==1) { throw new AppException('请先添加并同步本地账号，再绑定导入账号：'.$name); }
            $accounts[$ref]=(int)$matches[0]['id'];
        }
        $inactive=[];$seen=[];
        foreach ($doc['profiles'] as $row) {
            if (!is_array($row)) { throw new AppException('SaaS 条目格式不正确'); }
            $ref=$this->reference($row,$seen);$account=$this->mapped($accounts,$row['account_ref']??null,'SaaS 账号');
            $zone=$this->db->one("SELECT cf_id FROM zones WHERE account_id=? AND name=? AND status='active'",[$account,Validator::hostname($row['zone_name']??'')]);
            if (!$zone) { throw new AppException('请先同步 SaaS Zone：'.($row['zone_name']??'')); }
            $observed=$doc['format_version']===5&&($row['cname_target']??null)==='';
            $data=['name'=>Validator::label($row['name']??''),'account_id'=>$account,'zone_id'=>$zone['cf_id'],'cname_target'=>$observed?'':Validator::hostname($row['cname_target']??''),'fallback_origin'=>Validator::hostname($row['fallback_origin']??'')];
            $old=$this->db->one('SELECT * FROM profiles WHERE account_id=? AND zone_id=? AND deleted_at IS NULL',[$account,$zone['cf_id']]);
            if($observed&&!$old){throw new AppException('该文件包含按现有优选配置纳管的 SaaS，请先在本地读取并纳管已有域名，再导入配置；不会新建解析');}
            if ($old&&($old['cname_target']!==$data['cname_target']||$old['fallback_origin']!==$data['fallback_origin'])) { throw new AppException('本地 SaaS Zone 配置与文件不同，导入已停止：'.$row['zone_name'],409); }
            $id=$old?(int)$old['id']:$this->configuration->save('profiles',$data,$actor);
            if (!$old) { $created['profiles']++;if (!Validator::boolean($row['active']??true)) { $inactive[]=['profiles',$id]; } }
            $profiles[$ref]=$id;
        }
        $seen=[];
        foreach ($doc['entries'] as $row) {
            if (!is_array($row)) { throw new AppException('入口条目格式不正确'); }
            $ref=$this->reference($row,$seen);$kind=$row['kind']??'';
            if (!in_array($kind,['standard','cname','ip'],true)||!is_array($row['targets']??null)) { throw new AppException('导入入口类型或目标格式不正确'); }
            $targets=$kind==='standard'?[]:array_values(array_unique(array_map($kind==='ip'?Validator::publicIp(...):Validator::hostname(...),$row['targets'])));
            if (($kind==='cname'&&count($targets)!==1)||($kind==='ip'&&(count($targets)<1||count($targets)>10))) { throw new AppException('导入入口目标数量不正确'); }
            $name=Validator::label($row['name']??'');$old=null;
            $aliasFields=[];$aliasHostname=null;$aliasZone=null;
            if(isset($row['alias'])){
                if(!in_array($doc['format_version'],[4,5],true)||!is_array($row['alias'])){throw new AppException('自定义入口需要导出格式 4 或 5');}
                $aliasHostname=Validator::hostname($row['alias']['hostname']??'');$aliasAccount=$this->mapped($accounts,$row['alias']['account_ref']??null,'入口 DNS 账号');
                $zoneName=Validator::hostname($row['alias']['zone_name']??'');$zone=$this->db->one("SELECT id FROM zones WHERE account_id=? AND name=? AND status='active'",[$aliasAccount,$zoneName]);
                if(!$zone){throw new AppException('请先同步入口主域名：'.$zoneName);}$aliasZone=(int)$zone['id'];$aliasFields=['zone_id'=>$aliasZone,'hostname'=>$aliasHostname];
            }
            foreach ($this->db->all('SELECT * FROM entries WHERE deleted_at IS NULL AND kind=?',[$kind]) as $candidate) { if (($candidate['hostname']??null)===$aliasHostname&&($aliasZone===null||((int)$candidate['zone_ref']===$aliasZone))&&json_decode($candidate['targets'],true)===$targets&&(bool)$candidate['active']===Validator::boolean($row['active']??true)) { $old=$candidate;break; } }
            $id=$old?(int)$old['id']:$this->configuration->save('entries',['name'=>$name,'kind'=>$kind,'targets'=>$targets]+$aliasFields,$actor);
            if (!$old) { $created['entries']++;if (!Validator::boolean($row['active']??true)) { $inactive[]=['entries',$id]; } }
            $entries[$ref]=$id;
        }
        $seen=[];
        foreach ($doc['origins'] as $row) {
            if (!is_array($row)) { throw new AppException('回源条目格式不正确'); }
            $ref=$this->reference($row,$seen);$profile=$this->mapped($profiles,$row['profile_ref']??null,'回源 SaaS');
            $name=Validator::label($row['name']??'');$mode=$row['mode']??'';$hostname=Validator::hostname($row['hostname']??'');
            if (!in_array($mode,['custom','fallback'],true)) { throw new AppException('导入回源模式不正确'); }
            $old=$this->db->one('SELECT * FROM origins WHERE profile_id=? AND mode=? AND hostname=? AND source_ip IS NULL AND source_hostname IS NULL AND deleted_at IS NULL AND active=?',[$profile,$mode,$hostname,Validator::boolean($row['active']??true)?1:0]);
            $id=$old?(int)$old['id']:$this->configuration->save('origins',['name'=>$name,'profile_id'=>$profile,'mode'=>$mode,'hostname'=>$hostname],$actor);
            if (!$old) { $created['origins']++;if (!Validator::boolean($row['active']??true)) { $inactive[]=['origins',$id]; } }
            $origins[$ref]=$id;
        }
        // Disabled imported presets remain disabled; records referencing them must be corrected before import.
        foreach ($inactive as [$table,$id]) { $this->db->run('UPDATE '.$table.' SET active=0 WHERE id=?',[$id]); }
        $records=[];$hostnames=[];
        foreach ($doc['records'] as $row) {
            if (!is_array($row)) { throw new AppException('记录条目格式不正确'); }
            $hostname=Validator::hostname($row['hostname']??'');
            if (isset($hostnames[$hostname])) { throw new AppException('导入文件包含重复域名：'.$hostname); }$hostnames[$hostname]=true;
            $data=['hostnames'=>[$hostname],'profile_id'=>$this->mapped($profiles,$row['profile_ref']??null,'记录 SaaS'),'dns_account_id'=>$this->mapped($accounts,$row['dns_account_ref']??null,'记录 DNS 账号'),'entry_id'=>$this->mapped($entries,$row['entry_ref']??null,'记录入口'),'check'=>DomainConfig::checkOptions($row['check']??[])];
            if(isset($row['source_ip'])&&isset($row['source_hostname'])){throw new AppException('导入回源 IP 和 CNAME 只能选择一种');}
            if (isset($row['source_ip'])) { $data['source_ip']=$row['source_ip']; }
            elseif(isset($row['source_hostname'])){if($doc['format_version']<3){throw new AppException('CNAME 回源需要导出格式版本 3 或以上');}$data['source_hostname']=$row['source_hostname'];}
            else { $data['origin_id']=$this->mapped($origins,$row['origin_ref']??null,'记录回源'); }
            $data['dns_mode']=DnsMode::validate($row['dns_mode']??'preferred');
            if ($doc['format_version']===1&&$data['dns_mode']!=='preferred') { throw new AppException('非优选记录需要使用导出格式版本 2'); }
            $existing=$this->db->one('SELECT * FROM domains WHERE hostname=? AND deleted_at IS NULL FOR UPDATE',[$hostname]);
            if ($existing) {
                $profileRow=$this->db->one('SELECT * FROM profiles WHERE id=?',[$data['profile_id']]);
                $configs=new DomainConfig($this->db);
                $applied=$configs->applied($existing);
                $sameSource=!empty($applied['origin']['external_dns'])&&((isset($data['source_ip'])&&$data['source_ip']===($applied['origin']['source_ip']??null))||(isset($data['source_hostname'])&&$data['source_hostname']===($applied['origin']['source_hostname']??null)));
                $originId=$sameSource?(int)$applied['origin']['id']:(isset($data['source_ip'])?(new ManagedOrigin($this->db))->select($profileRow,$hostname,$data['source_ip']):(isset($data['source_hostname'])?(new ManagedOrigin($this->db))->selectHostname($profileRow,$hostname,$data['source_hostname']):$data['origin_id']));
                $expected=$configs->capture(['profile_id'=>$data['profile_id'],'entry_id'=>$data['entry_id'],'origin_id'=>$originId],null,null,$data['check']);
                $expected['dns_mode']=$data['dns_mode'];
                $configs->assertAvailable($expected,$data['dns_account_id']);
                $applied['dns_mode']=DnsMode::of($applied);
                unset($expected['observed_dns'],$applied['observed_dns'],$expected['origin']['external_dns'],$applied['origin']['external_dns']);
                // Portable preset IDs are local references; equal applied values can reuse different local presets.
                foreach (['entry','origin'] as $part) { unset($expected[$part]['id'],$expected[$part]['name'],$applied[$part]['id'],$applied[$part]['name']); }
                if ((int)$existing['dns_account_id']!==$data['dns_account_id']||$expected!=$applied) { throw new AppException('已有域名生效配置与导入计划不同，请在记录中编辑：'.$hostname,409); }
                $job=$this->db->one('SELECT id FROM jobs WHERE domain_id=? ORDER BY id DESC LIMIT 1',[$existing['id']]);
                $records[]=['hostname'=>$hostname,'domain_id'=>(int)$existing['id'],'job_id'=>(int)($job['id']??0),'reused'=>true];
                continue;
            }
            $result=(new DomainService($this->db))->create($data,$actor)['items'][0];
            if (isset($result['error'])) { throw new AppException('导入 '.$hostname.' 失败：'.$result['error'],409); }
            // Reuse must also preserve the applied values, not just equal preset IDs.
            if ($result['reused']) {
                $domain=$this->db->one('SELECT * FROM domains WHERE id=?',[$result['domain_id']]);$configs=new DomainConfig($this->db);
                $expected=$configs->capture($domain,null,null,$data['check']);$expected['dns_mode']=$data['dns_mode'];
                if (!DomainConfig::equivalent($configs->applied($domain),$expected)) { throw new AppException('已有域名生效配置与导入计划不同，请在记录中编辑：'.$hostname,409); }
            }
            $records[]=$result;
        }
        if ($applyDefaults) {
            $defaults=$doc['defaults']??[];
            if (!is_array($defaults)) { throw new AppException('默认配置格式不正确'); }
            foreach (['profile_id'=>$profiles,'dns_account_id'=>$accounts,'entry_id'=>$entries,'origin_id'=>$origins] as $key=>$map) { if (!empty($defaults[$key])) { $defaults[$key]=$this->mapped($map,$defaults[$key],'默认 '.$key); } }
            (new Settings($this->db))->save($defaults,$actor);
        }
        return ['created'=>$created,'records'=>$records,'new_tasks'=>count(array_filter($records,static fn($r):bool=>!$r['reused'])),'reused'=>count(array_filter($records,static fn($r):bool=>$r['reused'])),'defaults_applied'=>$applyDefaults];
    }
}

final class TransferPreview extends \RuntimeException
{
    public function __construct(public readonly array $result) { parent::__construct('preview'); }
}

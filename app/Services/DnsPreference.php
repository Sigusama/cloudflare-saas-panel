<?php
// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);
namespace Panel\Services;
use Panel\Core\{Database,Validator,AppException};

/** Preparing an ordinary DNS record for preferred mode is entirely local. */
final class DnsPreference
{
    public function __construct(private Database $db,private Cloud $cloud) {}
    public function plan(array $zone,array $record,array $rrset,array $input):array
    {
        if(array_filter($rrset,fn($r)=>$r['type']==='NS')){throw new AppException('该名称存在 NS 委派，请先处理委派后再切换优选');}
        $defaults=(new Settings($this->db))->read();
        $profile=$this->db->one('SELECT * FROM profiles WHERE id=? AND active=1 AND deleted_at IS NULL',[(int)($input['profile_id']??$defaults['profile_id'])]);
        $entry=$this->db->one('SELECT * FROM entries WHERE id=? AND active=1 AND deleted_at IS NULL',[(int)($input['entry_id']??$defaults['entry_id'])]);
        if(!$profile||!$entry){throw new AppException('请选择可用的 SaaS 服务与优选目标');}
        $host=Validator::hostname($record['name']);
        if($host===$profile['zone_name']||$host===$profile['fallback_origin']||$host===($entry['hostname']??null)){throw new AppException('该名称是 SaaS 站点、默认回源或优选入口，不能再把自身作为业务域名切换优选');}
        $entryConfig=(new EntryAlias($this->db))->capture($entry);$entryTargets=json_decode($entry['targets'],true);
        if($entry['kind']==='standard'&&empty($profile['cname_target'])){throw new AppException('该站点未设置标准 CNAME，请选择已配置的优选入口');}
        $options=['profile_id'=>(int)$profile['id'],'entry_id'=>(int)$entry['id'],'include_same_name'=>Validator::boolean($input['include_same_name']??false)];
        if(array_key_exists('check',$input)){$options['check']=DomainConfig::checkOptions($input['check'],$defaults['check']);}
        if(array_key_exists('source_ip',$input)&&array_key_exists('source_hostname',$input)){throw new AppException('回源 IP 和 CNAME 只能填写一个');}
        if(!empty($input['source_ip'])){$options['source_ip']=Validator::publicIp($input['source_ip']);}
        if(!empty($input['source_hostname'])){$options['source_hostname']=Validator::hostname($input['source_hostname']);}
        $items=$this->db->all("SELECT i.payload_json,i.run_id FROM discovery_items i JOIN discovery_runs r ON r.id=i.run_id WHERE i.hostname=? AND r.state='completed' AND r.demo=? ORDER BY i.run_id DESC LIMIT 100",[$host,(new SaasInventory($this->db,$this->cloud))->demo()?1:0]);
        foreach($items as $stored){$item=json_decode($stored['payload_json'],true);if($item['account_id']!==(int)$profile['account_id']||$item['zone_name']!==$profile['zone_name']){continue;}
            foreach($item['routes'] as $route){if((int)$route['dns_zone_ref']!==(int)$zone['id']||$route['blockers']){continue;}
                $options['adopt']=['run_id'=>(int)$stored['run_id'],'key'=>$item['key'],'route_id'=>$route['id'],'confirm_preferred'=>true,'cname_target'=>$item['profile']['cname_target']??''];
                (new SaasAdoption($this->db,$this->cloud,new Configuration($this->db,new \Panel\Core\Crypto(\Panel\Core\Config::read()['key']),$this->cloud)))->adopt($options['adopt']['run_id'],[...$options['adopt'],'preview'=>true],'preview');break 2;
            }
        }
        if(!isset($options['adopt'])){
            $addresses=array_values(array_filter($rrset,fn($r)=>in_array($r['type'],['A','AAAA','CNAME'],true)));
            if(count($addresses)>1&&(!$options['include_same_name']||!isset($options['source_ip'])&&!isset($options['source_hostname']))){throw new AppException('同名有多条地址记录：请明确填写回源，并勾选替换同名全部地址记录；预览将显示完整范围');}
            if(!isset($options['source_ip'])&&!isset($options['source_hostname'])){
                if($record['type']==='CNAME'){
                    if($record['content']===($entry['hostname']??null)||in_array($record['content'],$entryTargets,true)){throw new AppException('原 CNAME 已指向优选目标，请填写真实回源，不能把优选入口当源站');}
                    $options['source_hostname']=Validator::hostname($record['content']);
                }else{$options['source_ip']=Validator::publicIp($record['content']);}
            }
        }
        $options['profile_guard']=array_intersect_key($profile,array_flip(['id','account_id','zone_id','zone_name','cname_target','fallback_origin']));$options['entry_guard']=$entryConfig;
        $target=!empty($entry['hostname'])?$entry['hostname']:($entry['kind']==='standard'?$profile['cname_target']:($entryTargets[0]??''));
        $desired=!empty($entry['hostname'])||$entry['kind']!=='ip'?[['name'=>$host,'type'=>'CNAME','content'=>$target,'ttl'=>60,'proxied'=>false]]:array_map(fn($ip)=>['name'=>$host,'type'=>str_contains($ip,':')?'AAAA':'A','content'=>$ip,'ttl'=>60,'proxied'=>false],$entryTargets);
        return ['options'=>$options,'after'=>[...array_values(array_filter($rrset,fn($r)=>!in_array($r['type'],['A','AAAA','CNAME'],true))),...$desired],'message'=>'确认后准备 SaaS / TXT 验证与回源，再切换优选；原有无关 DNS 保留'];
    }
    public function start(array $zone,array $record,array $options,string $actor):array
    {
        $profile=$this->db->one('SELECT * FROM profiles WHERE id=? AND active=1 AND deleted_at IS NULL',[$options['profile_id']]);
        $entry=$this->db->one('SELECT * FROM entries WHERE id=? AND active=1 AND deleted_at IS NULL',[$options['entry_id']]);
        if(!$profile||!$entry||array_intersect_key($profile,$options['profile_guard'])!==$options['profile_guard']||(new EntryAlias($this->db))->capture($entry)!=$options['entry_guard']){throw new AppException('SaaS 服务或优选目标已改变，请重新预览',409);}
        if(isset($options['adopt'])){
            $adopt=$options['adopt'];$configuration=new Configuration($this->db,new \Panel\Core\Crypto(\Panel\Core\Config::read()['key']),$this->cloud);
            $result=(new SaasAdoption($this->db,$this->cloud,$configuration))->adopt($adopt['run_id'],[...$adopt,'preview'=>false],$actor);
            return ['domain_id'=>$result['domain_id'],'job_id'=>$result['job_id'],'phase'=>'inspect'];
        }
        if($this->db->value('SELECT id FROM domains WHERE hostname=?',[$record['name']])){throw new AppException('该名称已有面板管理或历史记录，请在纳管记录中处理',409);}
        $origins=new ManagedOrigin($this->db);$originId=$record['type']==='CNAME'?$origins->selectHostname($profile,$record['name'],$record['content']):$origins->select($profile,$record['name'],$record['content']);
        $config=(new DomainConfig($this->db))->capture(['profile_id'=>$profile['id'],'entry_id'=>$entry['id'],'origin_id'=>$originId],null,null,$options['check']??[]);$config['dns_mode']=!empty($record['proxied'])?'proxied':'dns_only';
        $config['observed_address_records']=array_values(array_filter($options['approved_dns'],fn($r)=>in_array($r['type'],['A','AAAA','CNAME'],true)));$config['observed_dns_mode']=$config['dns_mode'];
        (new DomainConfig($this->db))->assertAvailable($config,(int)$zone['account_id']);
        $id=$this->db->insert("INSERT INTO domains(hostname,profile_id,dns_account_id,dns_zone_id,entry_id,origin_id,status,hostname_status,ssl_status,cleanup_status,replace_existing,applied_config) VALUES(?,?,?,?,?,?,'active','not_required','not_required','not_required',1,?)",[$record['name'],$profile['id'],$zone['account_id'],$zone['cf_id'],$entry['id'],$originId,Validator::json($config)]);
        (new Audit($this->db))->write($actor,'dns.preferred_prepared','按原 DNS 保存本地配置，原解析尚未修改',$id);
        return ['domain_id'=>$id,'job_id'=>null,'phase'=>'configure'];
    }
    public function configure(int $domain,array $options,string $actor):array
    {
        $row=$this->db->one('SELECT * FROM domains WHERE id=?',[$domain]);
        return (new DomainService($this->db))->configure($domain,['dns_mode'=>'preferred','entry_id'=>$options['entry_id'],'expected_version'=>(int)$row['config_version'],'replace_existing'=>true,'preserve_dns_until_ready'=>true]+array_intersect_key($options,array_flip(['source_ip','source_hostname','approved_dns','check'])),$actor);
    }
}

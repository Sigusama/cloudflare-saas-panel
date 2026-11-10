<?php
// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);
namespace Panel\Services;

use Panel\Core\{Database,Validator,AppException};

final class EntryAlias
{
    public function __construct(private Database $db) {}

    public function fields(array $input,string $kind,array $targets,?array $old=null):array
    {
        $zoneRef=(int)($input['zone_id']??$input['zone_ref']??$old['zone_ref']??0);
        $name=$input['record_name']??$input['hostname']??$old['hostname']??'';
        if(!$zoneRef&&$name===''){return ['zone_ref'=>null,'hostname'=>null];}
        if($kind==='standard'){throw new AppException('自定义入口请选择优选 CNAME 或 IP 组');}
        if(!is_string($name)||trim($name)===''||!$zoneRef){throw new AppException('请选择入口主域名并填写自定义前缀，例如 saas');}
        $zone=(new ZoneRecords($this->db))->zone($zoneRef,true);$name=strtolower(trim($name));
        if(isset($input['hostname'])&&!Validator::zoneMatches(rtrim($name,'.'),$zone['name'])){throw new AppException('入口域名必须属于所选主域名');}
        $hostname=Validator::hostname(Validator::zoneMatches(rtrim($name,'.'),$zone['name'])?rtrim($name,'.'):$name.'.'.$zone['name']);
        if($hostname===$zone['name']||!Validator::zoneMatches($hostname,$zone['name'])){throw new AppException('入口必须是所选主域名下的自定义子域名');}
        $owner=$this->db->one("SELECT id FROM zones WHERE account_id=? AND status='active' AND (?=name OR ? LIKE CONCAT('%.',name)) ORDER BY CHAR_LENGTH(name) DESC LIMIT 1",[$zone['account_id'],$hostname,$hostname]);
        if(!$owner||(int)$owner['id']!==$zoneRef){throw new AppException('入口名称属于另一个更具体的 Zone，请选择对应主域名');}
        if($kind==='cname'&&in_array($hostname,$targets,true)){throw new AppException('入口 CNAME 不能指向入口自身');}
        if($this->db->value('SELECT id FROM entries WHERE hostname=? AND id<>?',[$hostname,(int)($old['id']??0)])){throw new AppException('该入口域名已有配置或历史保留，请使用不同前缀',409);}
        if($this->db->value('SELECT id FROM profiles WHERE cname_target=? OR fallback_origin=?',[$hostname,$hostname])){throw new AppException('该名称用于 CF 标准接入或默认回源，请换一个入口前缀',409);}
        if($this->db->value('SELECT id FROM origins WHERE hostname=?',[$hostname])){throw new AppException('该名称已保留为源站回源，不能用作优选入口',409);}
        if($this->db->value('SELECT id FROM domains WHERE hostname=? AND deleted_at IS NULL',[$hostname])){throw new AppException('该名称已作为业务记录管理，请换一个入口前缀',409);}
        $cursor=$kind==='cname'?($targets[0]??null):null;$seen=[$hostname=>true];
        while($cursor!==null){
            if(isset($seen[$cursor])){throw new AppException('优选入口之间存在 CNAME 循环');}$seen[$cursor]=true;
            $next=$this->db->one("SELECT kind,targets FROM entries WHERE hostname=? AND deleted_at IS NULL",[$cursor]);
            $cursor=$next&&$next['kind']==='cname'?(json_decode($next['targets'],true)[0]??null):null;
        }
        return ['zone_ref'=>$zoneRef,'hostname'=>$hostname];
    }

    public function assertBusiness(string $hostname,array $config):void
    {
        if($this->db->value('SELECT id FROM entries WHERE hostname=?',[$hostname])){throw new AppException('该名称已保留为共享优选入口，请换一个业务记录名称',409);}
        if(!DnsMode::preferred($config)||empty($config['entry']['hostname'])){return;}
        if(($config['origin']['source_hostname']??null)===$config['entry']['hostname']){throw new AppException('回源 CNAME 不能指向所选优选入口');}
        $cursor=$config['entry']['hostname'];$seen=[];
        while($cursor!==null){
            if($cursor===$hostname||isset($seen[$cursor])){throw new AppException('业务域名与优选入口之间存在 CNAME 循环');}$seen[$cursor]=true;
            $next=$this->db->one('SELECT kind,targets FROM entries WHERE hostname=?',[$cursor]);
            $cursor=$next&&$next['kind']==='cname'?(json_decode($next['targets'],true)[0]??null):null;
        }
    }

    public function capture(array $entry):array
    {
        $fields=array_intersect_key($entry,array_flip(['id','name','kind','targets']));
        if(!empty($entry['hostname'])){
            $zone=(new ZoneRecords($this->db))->zone((int)$entry['zone_ref']);
            $fields+=['hostname'=>$entry['hostname'],'dns_account_id'=>(int)$zone['account_id'],'dns_zone_id'=>$zone['cf_id'],'zone_name'=>$zone['name']];
        }
        return $fields;
    }

    public static function desired(array $entry):array
    {
        $targets=is_array($entry['targets'])?$entry['targets']:json_decode($entry['targets'],true);
        return array_map(static fn($target):array=>['name'=>$entry['hostname'],'type'=>$entry['kind']==='ip'?(str_contains($target,':')?'AAAA':'A'):'CNAME','content'=>$target,'proxied'=>false,'ttl'=>60],$targets);
    }

    public function ensure(array $domain,array $job,array $config,Cloud $cloud):void
    {
        $entry=$config['entry'];if(!DnsMode::preferred($config)||empty($entry['hostname'])){return;}
        if($domain['hostname']===$entry['hostname']){throw new AppException('业务记录不能 CNAME 到自身',409);}
        $desired=self::desired($entry);$encoded=Validator::json($desired);$marker='cf-saas-panel:entry:'.$entry['id'];
        $route=$this->db->one('SELECT * FROM entry_routes WHERE entry_id=?',[$entry['id']]);
        if($route&&($route['desired_json']!==$encoded||$route['hostname']!==$entry['hostname']||(int)$route['account_id']!==(int)$entry['dns_account_id']||$route['zone_id']!==$entry['dns_zone_id'])){throw new AppException('共享入口已有其他生效计划，请新建入口后切换',409);}
        if(!$route){
            $this->db->run('INSERT INTO entry_routes(entry_id,account_id,zone_id,hostname,desired_json) VALUES(?,?,?,?,?)',[$entry['id'],$entry['dns_account_id'],$entry['dns_zone_id'],$entry['hostname'],$encoded]);
            $route=$this->db->one('SELECT * FROM entry_routes WHERE entry_id=?',[$entry['id']]);
        }
        $dns=$cloud->dns((int)$entry['dns_account_id']);$records=$dns->records($entry['dns_zone_id'],$entry['hostname']);
        if($route['state']==='applied'){
            $saved=json_decode($route['records_json'],true);
            if(count($records)!==count($saved)){throw new AppException('共享入口 DNS 被外部增删，请检查后重试',409);}
            foreach($saved as $before){$current=array_values(array_filter($records,fn($record)=>$record['id']===$before['id']));if(!$current||!DnsChanges::same($current[0],$before)||(int)$current[0]['ttl']!==(int)$before['ttl']||($current[0]['comment']??'')!==($before['comment']??'')){throw new AppException('共享入口 DNS 被外部修改，停止覆盖',409);}}
            return;
        }
        $present=[];$external=false;
        foreach($records as $record){
            $match=null;foreach($desired as $index=>$want){if(DnsChanges::same($record,$want)){$match=$index;break;}}
            if($match===null||isset($present[$match])){throw new AppException('入口名称已有不匹配 DNS，面板不会覆盖：'.$entry['hostname'],409);}
            $present[$match]=$record;$external=$external||($record['comment']??'')!==$marker;
        }
        if($external&&count($present)!==count($desired)){throw new AppException('入口已有非面板管理的部分解析，不能补写或接管',409);}
        if(!$external){
            foreach($desired as $index=>$want){
                if(isset($present[$index])){continue;}
                $dns->create($entry['dns_zone_id'],$want+['comment'=>$marker]);
            }
            $records=$dns->records($entry['dns_zone_id'],$entry['hostname']);
            if(count($records)!==count($desired)){throw new AppException('入口 DNS 尚未全部写入，稍后对账重试',503);}
            foreach($desired as $want){if(!array_filter($records,fn($record)=>DnsChanges::same($record,$want)&&($record['comment']??'')===$marker)){throw new AppException('入口 DNS 写入后核对失败',503);}}
        }
        $this->db->run("UPDATE entry_routes SET state='applied',records_json=? WHERE entry_id=?",[Validator::json($records),$entry['id']]);
        (new Audit($this->db))->write('worker',$external?'entry.reused':'entry.applied','共享入口：'.$entry['hostname'].' → '.implode(' / ',array_column($desired,'content')),(int)$domain['id'],(int)$job['id']);
    }
}

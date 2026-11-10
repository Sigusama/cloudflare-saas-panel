<?php
// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);
namespace Panel\Services;

use Panel\Core\{Database,Validator,AppException};

/** Associates only API-authorized DNS. An external target is a boundary, not evidence of speed. */
final class InventoryLinks
{
    public array $zones=[];
    private array $index=[];
    public function __construct(private Database $db,int $run)
    {
        foreach($db->all('SELECT * FROM discovery_zones WHERE run_id=? ORDER BY position',[$run]) as $zone){
            $zone['dns']=json_decode($zone['dns_json'],true);$zone['fallback']=json_decode($zone['fallback_json']??'[]',true);$zone['hosts']=json_decode($zone['hostnames_json'],true);
            unset($zone['dns_json'],$zone['fallback_json'],$zone['hostnames_json']);$this->zones[(int)$zone['zone_ref']]=$zone;
            if($zone['dns_status']==='completed'){foreach($zone['dns'] as $record){$this->index[(int)$zone['zone_ref']][$record['name']][]=$record;}}
        }
    }
    public function subjects():array
    {
        $out=[];foreach($this->zones as $zone){if(!$zone['read_saas']){continue;}foreach($zone['hosts'] as $remote){$out[]=['zone_ref'=>(int)$zone['zone_ref'],'remote'=>$remote];}}return $out;
    }
    public function owners(string $hostname,?int $account=null):array
    {
        $owners=[];foreach($this->zones as $zone){if($account!==null&&(int)$zone['account_id']!==$account||!Validator::zoneMatches($hostname,$zone['zone_name'])){continue;}$id=(int)$zone['account_id'];if(!isset($owners[$id])||strlen($owners[$id]['zone_name'])<strlen($zone['zone_name'])){$owners[$id]=$zone;}}
        $unique=[];foreach($owners as $zone){$key=$zone['zone_id'].'|'.$zone['zone_name'].'|'.$zone['dns_status'].'|'.ObservedDns::fingerprint($this->at($zone,$hostname));$unique[$key]??=$zone;}return array_values($unique);
    }
    private function at(array $zone,string $name):array{return $this->index[(int)$zone['zone_ref']][$name]??[];}
    private function addresses(array $records):array{return array_values(array_filter($records,fn($r)=>in_array($r['type'],['A','AAAA','CNAME'],true)));}
    public static function targetSet(array $records):array
    {
        $types=array_unique(array_column($records,'type'));$cname=in_array('CNAME',$types,true);
        if(!$records||($cname&&(count($records)!==1||count($types)!==1))||(!$cname&&array_diff($types,['A','AAAA']))){throw new AppException('解析组合不能安全映射为一个 CNAME 或 IP 组');}
        $targets=array_values(array_unique(array_map(static fn($r)=>$cname?Validator::hostname($r['content']):Validator::publicIp($r['content']),$records)));
        if(count($targets)!==count($records)||count($targets)>10){throw new AppException('优选 IP 组需要 1 至 10 个不同地址');}
        return ['kind'=>$cname?'cname':'ip','targets'=>$targets];
    }
    private function route(array $dnsZone,string $hostname,array $standards,?array $entryZone=null):array
    {
        $business=$this->at($dnsZone,$hostname);$address=$this->addresses($business);
        $route=['id'=>(string)$dnsZone['zone_ref'].':'.($entryZone['zone_ref']??0),'dns_zone_ref'=>(int)$dnsZone['zone_ref'],'dns_account_id'=>(int)$dnsZone['account_id'],'account_name'=>$dnsZone['account_name'],'zone_name'=>$dnsZone['zone_name'],'records'=>$business,'entry'=>null,'entry_zone_ref'=>null,'entry_account_name'=>$entryZone['account_name']??null,'chain'=>[$hostname],'baselines'=>[],'reasons'=>[],'blockers'=>[],'known'=>false];
        if($dnsZone['dns_status']!=='completed'){$route['blockers'][]='业务主域名的 DNS 未读取完整';return $route;}
        if(!$address){$route['blockers'][]='授权 Zone 内未找到该业务域名的 A / AAAA / CNAME';return $route;}
        if(array_filter($address,fn($r)=>!empty($r['proxied']))){$route['blockers'][]='业务解析开启了代理，当前优选纳管需保留为未代理解析；此项可只读查看';return $route;}
        try{
            $direct=self::targetSet($address);
            if($direct['kind']==='ip'){$route['entry']=$direct;$route['chain'][]=implode(' / ',$direct['targets']);$route['reasons'][]='已读取业务 IP 组，但 DNS 不能证明其优选用途';return $route;}
            $target=$direct['targets'][0];$route['chain'][]=$target;
            if(in_array($target,$standards,true)){$route['entry']=['kind'=>'standard','targets'=>[]];$route['standard_target']=$target;$route['known']=true;return $route;}
            if(!$entryZone){$route['entry']=$direct;$route['reasons'][]='CNAME 目标不在已读取的授权 DNS 内，优选用途待确认';return $route;}
            if($entryZone['dns_status']!=='completed'){$route['blockers'][]='自定义入口所属 Zone 的 DNS 未读取完整';return $route;}
            $entryRecords=$this->at($entryZone,$target);
            if(!$entryRecords){$route['blockers'][]='此授权 Zone 内没有入口 DNS，请核对入口所属账号';return $route;}
            if(array_filter($entryRecords,fn($r)=>!empty($r['proxied']))){$route['entry']=$direct;$route['reasons'][]='目标为代理域名，按现有直接 CNAME 保留，优选用途待确认';return $route;}
            $entry=self::targetSet($entryRecords);$entry['hostname']=$target;$entry['zone_ref']=(int)$entryZone['zone_ref'];$route['entry']=$entry;$route['entry_zone_ref']=(int)$entryZone['zone_ref'];
            if($target===$entryZone['zone_name']){$route['blockers'][]='当前入口为主域名根记录，面板的自定义入口仅支持子域名';}
            $route['baselines'][]=['zone_ref'=>(int)$entryZone['zone_ref'],'hostname'=>$target,'records'=>$entryRecords];
            $seen=[$hostname=>true,$target=>true];$current=$entry;$hops=0;
            while($current['kind']==='cname'){
                $next=$current['targets'][0];if(isset($seen[$next])){throw new AppException('检测到 CNAME 循环');}$seen[$next]=true;$route['chain'][]=$next;
                if(++$hops>8){throw new AppException('CNAME 链超过 8 跳，需人工检查');}
                $owners=$this->owners($next);if(!$owners){break;}if(count($owners)!==1){$route['blockers'][]='后续 CNAME 在多个授权账号中同名，无法唯一关联';break;}
                $owner=$owners[0];if($owner['dns_status']!=='completed'){$route['blockers'][]='后续 CNAME 的 DNS 未读取完整';break;}
                $records=$this->at($owner,$next);if(!$records||array_filter($records,fn($r)=>!empty($r['proxied']))){break;}
                $route['baselines'][]=['zone_ref'=>(int)$owner['zone_ref'],'hostname'=>$next,'records'=>$records];$current=self::targetSet($records);
            }
            if($current['kind']==='ip'){$route['chain'][]=implode(' / ',$current['targets']);}
            $known=$this->db->one('SELECT * FROM entries WHERE hostname=? AND zone_ref=? AND active=1 AND deleted_at IS NULL',[$target,$entryZone['zone_ref']]);
            $route['known']=$known&&$known['kind']===$entry['kind']&&ObservedDns::sameTargets(json_decode($known['targets'],true),$entry['targets']);
            if(!$route['known']){$route['reasons'][]='入口解析已关联，是否用于优选仍需确认（不代表测速结果）';}
        }catch(AppException $e){$route['blockers'][]=$e->getMessage();}
        return $route;
    }
    public function analyse(array $subject):array
    {
        $zone=$this->zones[$subject['zone_ref']];$remote=$subject['remote'];$host=$remote['hostname'];
        $item=['key'=>hash('sha256',$zone['account_id'].'|'.$zone['zone_id'].'|'.$remote['id']),'hostname'=>$host,'remote'=>$remote,'account_id'=>(int)$zone['account_id'],'account_name'=>$zone['account_name'],'zone_ref'=>(int)$zone['zone_ref'],'zone_name'=>$zone['zone_name'],'profile'=>['fallback_origin'=>$zone['fallback']['origin']??'','fallback_status'=>$zone['fallback']['status']??'unknown','cname_candidates'=>[],'cname_target'=>null],'origin'=>null,'routes'=>[],'reasons'=>[],'blockers'=>[],'confidence'=>'pending'];
        try{Validator::hostname($host);if($host===$zone['zone_name']){throw new AppException('业务域名不能等于 SaaS 主域名根记录');}if($remote['id']===''||strlen($remote['id'])>64||!preg_match('/^[A-Za-z0-9_-]+$/D',$remote['id'])){throw new AppException('云端主机名标识不受支持');}}
        catch(AppException $e){$item['blockers'][]=$e->getMessage();}
        if($zone['hostnames_status']!=='completed'){$item['blockers'][]='SaaS 主机名列表读取未完成';}
        if(in_array($remote['status'],['deleted','pending_deletion','moved','blocked','pending_blocked','test_failed','test_blocked'],true)){$item['blockers'][]='云端主机名已删除、迁移或被阻止，先在 CF 核对状态';}
        $fallback=$item['profile']['fallback_origin'];
        if($zone['fallback_status']!=='completed'||$fallback===''){$item['blockers'][]='默认回源未读取成功，请检查 SaaS 配置及权限';}
        else{
            try{Validator::hostname($fallback);if(!Validator::zoneMatches($fallback,$zone['zone_name'])){throw new AppException('默认回源不属于该 SaaS Zone');}}
            catch(AppException $e){$item['blockers'][]=$e->getMessage();}
            foreach($zone['dns'] as $record){if($zone['dns_status']==='completed'&&$record['type']==='CNAME'&&!empty($record['proxied'])&&strtolower(rtrim($record['content'],'.'))===$fallback){try{$candidate=Validator::hostname($record['name']);if($candidate!==$fallback&&Validator::zoneMatches($candidate,$zone['zone_name'])){$item['profile']['cname_candidates'][]=$candidate;}}catch(AppException){}}}
            $item['profile']['cname_candidates']=array_values(array_unique($item['profile']['cname_candidates']));sort($item['profile']['cname_candidates']);
            if(!$item['profile']['cname_candidates']){$item['reasons'][]='保留现有优选入口；未设置用于新建接入的标准 CNAME';}
            elseif(count($item['profile']['cname_candidates'])===1){$item['profile']['cname_target']=$item['profile']['cname_candidates'][0];}
        }
        $source=$remote['custom_origin_server']?:$fallback;
        try{
            $source=Validator::hostname($source);$owners=$this->owners($source,(int)$zone['account_id']);
            if(count($owners)!==1||$owners[0]['dns_status']!=='completed'){throw new AppException('回源 DNS 不在已完整读取的 SaaS 账号内');}
            $sourceZone=$owners[0];$records=$this->at($sourceZone,$source);$addresses=$this->addresses($records);
            if(!$addresses||array_filter($addresses,fn($r)=>empty($r['proxied']))){throw new AppException('回源缺少可核验的橙云 A / AAAA / CNAME');}
            $set=self::targetSet($addresses);$item['origin']=['hostname'=>$source,'mode'=>$remote['custom_origin_server']?'custom':'fallback','kind'=>$set['kind'],'targets'=>$set['targets'],'zone_ref'=>(int)$sourceZone['zone_ref'],'records'=>$records];
        }catch(AppException $e){$item['blockers'][]=$e->getMessage();}
        foreach($this->owners($host) as $dnsZone){
            $business=$this->addresses($this->at($dnsZone,$host));$target=count($business)===1&&$business[0]['type']==='CNAME'?strtolower(rtrim($business[0]['content'],'.')):null;
            $entryZones=$target&&!in_array($target,$item['profile']['cname_candidates'],true)?$this->owners($target):[];
            foreach($entryZones?:[null] as $entryZone){$route=$this->route($dnsZone,$host,$item['profile']['cname_candidates'],$entryZone);if(isset($route['standard_target'])){$route['baselines'][]=['zone_ref'=>(int)$zone['zone_ref'],'hostname'=>$route['standard_target'],'records'=>$this->at($zone,$route['standard_target'])];}$item['routes'][]=$route;}
        }
        if(!$item['routes']){$item['blockers'][]='业务 DNS 不在已授权 Zone 中，请添加相应 DNS 账号后重新读取';}
        $standardTargets=array_values(array_unique(array_column($item['routes'],'standard_target')));
        if(count($standardTargets)===1){$item['profile']['cname_target']=$standardTargets[0];}
        $local=$this->db->one('SELECT * FROM profiles WHERE account_id=? AND zone_id=? AND deleted_at IS NULL',[$zone['account_id'],$zone['zone_id']]);
        if($local){if(!$local['active']||$local['fallback_origin']!==$fallback||($local['cname_target']!==''&&!in_array($local['cname_target'],$item['profile']['cname_candidates'],true))){$item['blockers'][]='本地 SaaS 服务设置已停用或与 CF 读取结果不同，请先核对';}elseif($local['cname_target']!==''){$item['profile']['cname_target']=$local['cname_target'];}}
        if(!$item['profile']['cname_target']&&$item['profile']['cname_candidates']){$item['reasons'][]='存在多个标准 CNAME，请选择对应的服务入口';}
        if(count($item['routes'])>1){$item['reasons'][]='存在多个 DNS 关联方案，请选择业务 / 入口所属账号';}
        $valid=array_values(array_filter($item['routes'],fn($r)=>!$r['blockers']));
        if(!$item['blockers']&&count($valid)===1&&count($item['routes'])===1&&$valid[0]['known']&&($item['profile']['cname_target']||!$item['profile']['cname_candidates'])){$item['confidence']='confirmed';}
        elseif($item['blockers']||!$valid){$item['confidence']='blocked';}
        if($remote['status']!=='active'||$remote['ssl']['status']!=='active'){$item['reasons'][]='云端主机名或证书尚未就绪；纳管后保留待检查状态';}
        return $item;
    }
}

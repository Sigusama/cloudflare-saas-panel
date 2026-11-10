<?php
// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);
namespace Panel\Services;

use Panel\Core\{Database, Validator, AppException};

/** Complete read-only DNS snapshots; synchronization never mutates remote records. */
final class ZoneDns
{
    public function __construct(private Database $db) {}
    public function snapshot(int $zone):array
    {
        $row=$this->db->one('SELECT * FROM zone_dns_snapshots WHERE zone_ref=?',[$zone]);
        return ['loaded'=>$row!==null,'synced_at'=>$row['synced_at']??null,'records'=>$row?json_decode($row['records_json'],true,512,JSON_THROW_ON_ERROR):[]];
    }
    public function store(int $zone,array $records):void
    {
        $records=ObservedDns::records($records);$ids=[];
        foreach($records as $record){if(empty($record['id'])||isset($ids[$record['id']])||$record['type']===''||$record['name']===''){throw new AppException('Cloudflare DNS 列表不完整或包含重复标识，保留上次读取结果',503);}$ids[$record['id']]=true;}
        $json=Validator::json($records);
        if(count($records)>50000||strlen($json)>8000000){throw new AppException('DNS 列表超过 50000 条或 8 MB，保留上次完整结果');}
        $this->db->run('INSERT INTO zone_dns_snapshots(zone_ref,records_json) VALUES(?,?) ON DUPLICATE KEY UPDATE records_json=VALUES(records_json),synced_at=UTC_TIMESTAMP()',[$zone,$json]);
    }
    public function sync(int $zoneId,Cloud $cloud,string $actor):array
    {
        $zone=(new ZoneRecords($this->db))->zone($zoneId,true);$lock='cf_panel_dns_'.$zoneId;
        if(!(int)$this->db->value('SELECT GET_LOCK(?,0)',[$lock])){throw new AppException('该主域名正在同步，请稍后刷新',409);}
        try{
            $reader=new SaasInventory($this->db,$cloud);$records=[];$complete=false;
            for($page=1;$page<=1000;$page++){
                $response=$reader->readPage(['zone_ref'=>$zoneId,'zone_id'=>$zone['cf_id'],'account_id'=>$zone['account_id']],'dns',$page);
                $rows=$response['result']??null;
                if(!is_array($rows)||!array_is_list($rows)||array_filter($rows,fn($r)=>!is_array($r))){throw new AppException('Cloudflare DNS 列表响应不正确',503);}
                $records=array_merge($records,$rows);
                if(count($records)>50000||strlen(Validator::json($records))>8000000){throw new AppException('DNS 列表超过读取上限，保留上次完整结果');}
                $pages=$response['result_info']['total_pages']??null;
                if($pages!==null?$page>=max(1,(int)$pages):count($rows)<100){$complete=true;break;}
            }
            if(!$complete||isset($response['result_info']['total_count'])&&(int)$response['result_info']['total_count']!==count($records)){throw new AppException('Cloudflare DNS 分页结果不完整，保留上次完整列表',503);}
            $this->store($zoneId,$records);
            (new Audit($this->db))->write($actor,'dns.synchronized','只读同步 '.$zone['name'].'：'.count($records).' 条 DNS，云端解析未修改');
            return ['count'=>count($records),'message'=>'已载入全部 DNS 记录，原解析保持不变','synced_at'=>$this->snapshot($zoneId)['synced_at']];
        }finally{$this->db->value('SELECT RELEASE_LOCK(?)',[$lock]);}
    }
}

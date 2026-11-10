<?php
// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);
namespace Panel\Services;

use Panel\Core\{Database,Validator,AppException};

final class BulkRecords
{
    public function __construct(private Database $db,private ?Cloud $cloud=null) {}

    public function execute(int $zoneId,array $input,string $actor):array
    {
        $action=$input['action']??'';
        if (!in_array($action,['set_mode','delete','update'],true)) { throw new AppException('操作请选择 set_mode、delete 或 update'); }
        $preview=Validator::boolean($input['preview']??true);
        $mode=$action==='set_mode'?DnsMode::validate($input['dns_mode']??null):($input['mode']??'delete_records');
        if ($action==='delete'&&!in_array($mode,['delete_records','forget','restore','delete_remote'],true)) { throw new AppException('批量删除方式不正确'); }
        $records=$input['records']??null;
        if (!is_array($records)||!array_is_list($records)||count($records)<1||count($records)>50) { throw new AppException('每批请选择 1 至 50 条记录'); }
        $seen=[];
        foreach ($records as $record) {
            $managed=is_array($record)&&is_int($record['id']??null)&&$record['id']>0&&is_int($record['expected_version']??null)&&$record['expected_version']>0;
            $raw=is_array($record)&&is_string($record['id']??null)&&preg_match('/^cf:[A-Za-z0-9_-]{1,64}$/D',$record['id'])&&is_string($record['expected_fingerprint']??null)&&preg_match('/^[a-f0-9]{64}$/D',$record['expected_fingerprint']);
            if(!$managed&&!$raw){throw new AppException('纳管记录需正整数 id / expected_version；原 DNS 需 cf: 标识和 expected_fingerprint');}
            if (isset($seen[$record['id']])) { throw new AppException('同一批次不能重复提交记录'); }$seen[$record['id']]=true;
        }
        $zone=(new ZoneRecords($this->db))->zone($zoneId,!($action==='delete'&&$mode==='forget'));
        $batchId='batch-'.bin2hex(random_bytes(8));$items=[];$service=new DomainService($this->db);$preferredNames=[];
        foreach ($records as $record) {
            if(is_string($record['id'])){
                try{
                    if(!$this->cloud){throw new AppException('原 DNS 操作需要云端连接',500);}
                    if($action==='delete'&&$mode!=='delete_records'){throw new AppException('原 DNS 请选择直接删除记录，不适用恢复或仅移除管理');}
                    $cached=(new ZoneDns($this->db))->snapshot($zoneId)['records'];$name=null;foreach($cached as $r){if('cf:'.$r['id']===$record['id']){$name=$r['name'];break;}}
                    if($action==='set_mode'&&$mode==='preferred'&&isset($preferredNames[$name??''])){$result=['id'=>$record['id'],'hostname'=>$name,'status'=>'unchanged','message'=>'同名地址已合并到本批次的优选任务'];}
                    else{$result=(new DnsOperations($this->db,$this->cloud))->plan($zoneId,$record,$action,$input,$actor,$preview);if($action==='set_mode'&&$mode==='preferred'&&in_array($result['status'],['ready','queued'],true)){$preferredNames[$name]=true;}}
                }catch(AppException $error){$result=['id'=>$record['id'],'hostname'=>$name??null,'expected_fingerprint'=>$record['expected_fingerprint'],'status'=>'failed','error'=>['code'=>$error->errorCode,'message'=>$error->getMessage(),'status'=>$error->status]];}
                $items[]=$result;continue;
            }
            $hostname=null;
            try {
                $result=$this->db->transaction(function()use($record,$zone,$action,$mode,$preview,$service,$actor,$batchId,&$hostname):array {
                    $domain=$this->db->one('SELECT * FROM domains WHERE id=? AND dns_account_id=? AND dns_zone_id=? AND deleted_at IS NULL FOR UPDATE',[$record['id'],$zone['account_id'],$zone['cf_id']]);
                    if (!$domain) { throw new AppException('记录不属于此主域名或已移除',404); }
                    $hostname=$domain['hostname'];
                    if($action==='update'){throw new AppException('已纳管地址请使用记录编辑接口修改回源或优选目标');}
                    if ((int)$domain['config_version']!==$record['expected_version']) { throw new AppException('记录版本已更新，请重新选择后预览',409,'version_conflict'); }
                    $result=$action==='set_mode'?$service->configure($record['id'],['dns_mode'=>$mode,'expected_version'=>$record['expected_version']]+($mode==='preferred'&&isset($input['entry_id'])?['entry_id'=>(int)$input['entry_id']]:[]),$actor):$service->remove($record['id'],['mode'=>$mode,'expected_version'=>$record['expected_version']],$actor);
                    $status=$action==='set_mode'&&empty($result['changed'])?'unchanged':($preview?'ready':(!empty($result['removed'])?'removed':'queued'));
                    $item=['id'=>$record['id'],'hostname'=>$hostname,'expected_version'=>$record['expected_version'],'status'=>$status];
                    if (!$preview&&isset($result['job_id'])) {
                        $item['job_id']=(int)$result['job_id'];
                        $context=json_decode($this->db->value('SELECT context_json FROM jobs WHERE id=?',[$result['job_id']])?:'{}',true);$context['batch_id']=$batchId;
                        $this->db->run('UPDATE jobs SET context_json=? WHERE id=?',[Validator::json($context),$result['job_id']]);
                    }
                    if ($preview) { throw new BulkRecordPreview($item); }
                    (new Audit($this->db))->write($actor,'bulk.item',$batchId.' '.$action.' '.$mode.' '.$status,$record['id'],$item['job_id']??null);
                    return $item;
                });
            } catch (BulkRecordPreview $result) { $result=$result->item; }
            catch (AppException $error) { $result=['id'=>$record['id'],'hostname'=>$hostname,'expected_version'=>$record['expected_version'],'status'=>'failed','error'=>['code'=>$error->errorCode,'message'=>$error->getMessage(),'status'=>$error->status]]; }
            $items[]=$result;
        }
        $summary=['total'=>count($items),'ready'=>0,'queued'=>0,'removed'=>0,'unchanged'=>0,'failed'=>0];
        foreach ($items as $item) { $summary[$item['status']]++; }
        if (!$preview) { (new Audit($this->db))->write($actor,'bulk.submitted',$batchId.' Zone #'.$zoneId.' '.$action.' '.$mode.'：排队 '.$summary['queued'].'、移除管理 '.$summary['removed'].'、无变化 '.$summary['unchanged'].'、失败 '.$summary['failed']); }
        return ['batch_id'=>$preview?null:$batchId,'zone_id'=>$zoneId,'action'=>$action,'mode'=>$mode,'preview'=>$preview,'summary'=>$summary,'items'=>$items];
    }
}

final class BulkRecordPreview extends \RuntimeException
{
    public function __construct(public readonly array $item) { parent::__construct('preview'); }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

use Panel\Cloudflare\CloudflareClient;
use Panel\Core\AppException;

final class FakeCloudflare extends CloudflareClient
{
    public array $records=[];
    public array $hostnames=[];
    public array $requests=[];
    public bool $ready=false;
    public bool $failDelete=false;
    public bool $loseCreateResponse=false;
    public int $order=1;
    public bool $includeOwnership=true;
    public bool $losePatchResponse=false;
    public bool $loseDnsPatchResponse=false;
    public bool $loseDnsDeleteResponse=false;
    public bool $loseHostnameResponse=false;
    public ?array $fallbackOrigin=null;
    public bool $failFallbackRead=false;
    public array $readFailures=[];
    public bool $preserveHostStatus=false;
    private int $sequence=0;
    public function __construct(public string $zone,public string $zoneName) { parent::__construct('test-only'); }
    public function request(string $method,string $path,?array $body=null,array $query=[]):array
    {
        $this->requests[]=['method'=>$method,'path'=>$path,'query'=>$query];
        if($method==='GET'&&!empty($this->readFailures[$path]['times'])&&(!isset($this->readFailures[$path]['page'])||($query['page']??1)===$this->readFailures[$path]['page'])){$failure=&$this->readFailures[$path];$failure['times']--;throw new AppException('模拟读取失败',$failure['status']);}
        if ($path==='/zones') { return ['success'=>true,'result'=>[['id'=>$this->zone,'name'=>$this->zoneName,'status'=>'active']],'result_info'=>['total_pages'=>1]]; }
        if (str_ends_with($path,'/custom_hostnames/fallback_origin')) { if($this->failFallbackRead){throw new AppException('模拟无读取权限',422);}return ['success'=>true,'result'=>$this->fallbackOrigin??['origin'=>'origin.saas.test','status'=>'active']]; }
        if (preg_match('#/dns_records(?:/([^/]+))?$#',$path,$m)) {
            $id=$m[1]??null;
            if ($method==='GET'&&$id) {
                if (!isset($this->records[$id])) { throw new AppException('not found',404); }
                return ['success'=>true,'result'=>$this->records[$id]];
            }
            if ($method==='GET') { return $this->page(array_values(array_filter($this->records,static fn($r):bool=>(!isset($query['name'])||$r['name']===$query['name'])&&(!isset($query['type'])||$r['type']===$query['type'])&&(!isset($query['proxied'])||!empty($r['proxied'])===($query['proxied']==='true')))),$query); }
            if ($method==='POST') {
                $id='record-'.++$this->sequence;$record=$body+['id'=>$id,'ttl'=>1,'proxied'=>false];$this->records[$id]=$record;
                if ($this->loseCreateResponse) { $this->loseCreateResponse=false;throw new AppException('模拟响应丢失',503); }
                return ['success'=>true,'result'=>$record];
            }
            if ($method==='DELETE') {
                if ($this->failDelete) { throw new AppException('模拟删除暂时失败',503); }
                unset($this->records[$id]);if($this->loseDnsDeleteResponse){$this->loseDnsDeleteResponse=false;throw new AppException('模拟删除响应丢失',503);}return ['success'=>true,'result'=>['id'=>$id]];
            }
            if(in_array($method,['PATCH','PUT'],true)&&$id){if(!isset($this->records[$id])){throw new AppException('not found',404);}$this->records[$id]=$method==='PATCH'?array_replace($this->records[$id],$body):$body+['id'=>$id];if($this->loseDnsPatchResponse){$this->loseDnsPatchResponse=false;throw new AppException('模拟 DNS 修改响应丢失',503);}return ['success'=>true,'result'=>$this->records[$id]];}
        }
        if (preg_match('#/custom_hostnames(?:/([^/]+))?$#',$path,$m)) {
            $id=$m[1]??null;
            if ($method==='GET'&&!$id) { return $this->page(array_values(array_filter($this->hostnames,static fn($r):bool=>!isset($query['hostname'])||$r['hostname']===$query['hostname'])),$query); }
            if ($method==='POST') {
                $id='host-'.++$this->sequence;
                $this->hostnames[$id]=$body+['id'=>$id,'status'=>'pending'];
                if ($this->loseHostnameResponse) { $this->loseHostnameResponse=false;throw new AppException('模拟主机名创建响应丢失',503); }
                return ['success'=>true,'result'=>$this->host($id)];
            }
            if ($method==='GET'&&$id) { if (!isset($this->hostnames[$id])) { throw new AppException('not found',404); }return ['success'=>true,'result'=>$this->host($id)]; }
            if ($method==='PATCH'&&$id) {
                if (!isset($this->hostnames[$id])) { throw new AppException('not found',404); }
                foreach ($body as $field=>$value) { if ($value==='') { unset($this->hostnames[$id][$field]); }else { $this->hostnames[$id][$field]=$value; } }
                if ($this->losePatchResponse) { $this->losePatchResponse=false;throw new AppException('模拟 PATCH 响应丢失',503); }
                return ['success'=>true,'result'=>$this->host($id)];
            }
            if ($method==='DELETE') { unset($this->hostnames[$id]);return ['success'=>true,'result'=>['id'=>$id]]; }
        }
        throw new RuntimeException('Unhandled fake request '.$method.' '.$path);
    }
    private function page(array $rows,array $query):array
    {
        $per=(int)($query['per_page']??50);$page=(int)($query['page']??1);
        return ['success'=>true,'result'=>array_slice($rows,($page-1)*$per,$per),'result_info'=>['total_pages'=>max(1,(int)ceil(count($rows)/$per)),'total_count'=>count($rows)]];
    }
    private function host(string $id):array
    {
        $host=$this->hostnames[$id];$hostname=$host['hostname'];
        if($this->preserveHostStatus){return $host;}
        $host['status']=$this->ready?'active':'pending';
        $host['ssl']=['status'=>$this->ready?'active':'pending_validation','validation_records'=>[['txt_name'=>'_acme-challenge.'.$hostname,'txt_value'=>'certificate-token-'.$this->order]]];
        if ($this->includeOwnership) { $host['ownership_verification']=['name'=>'_cf-custom-hostname.'.$hostname,'type'=>'txt','value'=>'ownership-token']; }
        return $host;
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);
namespace Panel\Services;

use Panel\Core\{Database,Validator,AppException};

final class Settings
{
    public function __construct(private Database $db) {}
    public function read():array
    {
        $json=$this->db->value("SELECT value FROM settings WHERE name='defaults'");
        return $json?json_decode($json,true):['profile_id'=>null,'dns_account_id'=>null,'entry_id'=>null,'origin_id'=>null,'check'=>DomainConfig::checkOptions([])];
    }
    public function save(array $input,string $actor):array
    {
        $values=$this->read();
        foreach (['profile_id'=>'profiles','dns_account_id'=>'accounts','entry_id'=>'entries','origin_id'=>'origins'] as $key=>$table) {
            if (!array_key_exists($key,$input)) { continue; }
            $id=$input[$key]===null||$input[$key]===''?null:(int)$input[$key];
            if ($id!==null&&!(int)$this->db->value('SELECT COUNT(*) FROM '.$table.' WHERE id=? AND active=1 AND deleted_at IS NULL',[$id])) { throw new AppException('默认项不存在或已停用：'.$key); }
            $values[$key]=$id;
        }
        if ($values['origin_id']&&$values['profile_id']&&(int)$this->db->value('SELECT profile_id FROM origins WHERE id=?',[$values['origin_id']])!==$values['profile_id']) { throw new AppException('默认回源不属于默认 SaaS 配置'); }
        if ($values['origin_id']&&$this->db->value('SELECT COALESCE(source_ip,source_hostname,external_dns_json) FROM origins WHERE id=?',[$values['origin_id']])) { throw new AppException('每条记录的自动 / 纳管回源不能设为共享默认回源'); }
        $values['check']=DomainConfig::checkOptions($input['check']??[],$values['check']??[]);
        $this->db->run("INSERT INTO settings(name,value) VALUES('defaults',?) ON DUPLICATE KEY UPDATE value=VALUES(value)",[Validator::json($values)]);
        (new Audit($this->db))->write($actor,'settings.defaults','更新新任务默认配置，已有域名不变');
        return $values;
    }
    public function clearReference(string $resource,int $id):void
    {
        $key=['accounts'=>'dns_account_id','profiles'=>'profile_id','entries'=>'entry_id','origins'=>'origin_id'][$resource];
        $values=$this->read();
        $original=$values;
        if ($resource==='accounts'&&!empty($values['profile_id'])&&(int)$this->db->value('SELECT account_id FROM profiles WHERE id=?',[$values['profile_id']])===$id) { $values['profile_id']=null;$values['origin_id']=null; }
        if ((int)($values[$key]??0)===$id) {
            $values[$key]=null;
            if ($resource==='profiles') { $values['origin_id']=null; }
        }
        if ($values!==$original) { $this->db->run("INSERT INTO settings(name,value) VALUES('defaults',?) ON DUPLICATE KEY UPDATE value=VALUES(value)",[Validator::json($values)]); }
    }
}

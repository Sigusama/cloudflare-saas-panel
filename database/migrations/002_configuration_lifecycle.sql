ALTER TABLE accounts ADD COLUMN deleted_at DATETIME NULL;
ALTER TABLE profiles ADD COLUMN deleted_at DATETIME NULL;
ALTER TABLE entries ADD COLUMN deleted_at DATETIME NULL;
ALTER TABLE origins ADD COLUMN deleted_at DATETIME NULL;
ALTER TABLE domains ADD COLUMN applied_config MEDIUMTEXT NULL;
ALTER TABLE domains ADD COLUMN config_version INT NOT NULL DEFAULT 1;
ALTER TABLE domains ADD COLUMN deleted_at DATETIME NULL;
CREATE TABLE IF NOT EXISTS domain_changes (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 domain_id BIGINT UNSIGNED NOT NULL,
 job_id BIGINT UNSIGNED NULL,
 from_config MEDIUMTEXT NOT NULL,
 to_config MEDIUMTEXT NOT NULL,
 status VARCHAR(40) NOT NULL DEFAULT 'queued',
 remote_before_json MEDIUMTEXT NULL,
 remote_after_json MEDIUMTEXT NULL,
 baseline_dns_json MEDIUMTEXT NULL,
 remote_write_planned TINYINT NOT NULL DEFAULT 0,
 last_error TEXT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY (domain_id) REFERENCES domains(id),
 FOREIGN KEY (job_id) REFERENCES jobs(id),
 KEY change_history(domain_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
UPDATE domains d JOIN profiles p ON p.id=d.profile_id JOIN entries e ON e.id=d.entry_id JOIN origins o ON o.id=d.origin_id SET d.applied_config=JSON_OBJECT('profile',JSON_OBJECT('id',p.id,'account_id',p.account_id,'zone_id',p.zone_id,'zone_name',p.zone_name,'cname_target',p.cname_target,'fallback_origin',p.fallback_origin),'entry',JSON_OBJECT('id',e.id,'name',e.name,'kind',e.kind,'targets',e.targets),'origin',JSON_OBJECT('id',o.id,'name',o.name,'profile_id',o.profile_id,'mode',o.mode,'hostname',o.hostname),'check',JSON_OBJECT('path','/','statuses',JSON_ARRAY(200,201,202,204,301,302,303,307,308),'keyword','')) WHERE d.applied_config IS NULL;

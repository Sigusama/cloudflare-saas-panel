ALTER TABLE origins ADD COLUMN source_hostname VARCHAR(253) NULL;
ALTER TABLE managed_sources MODIFY COLUMN source_ip VARCHAR(45) NULL;
ALTER TABLE managed_sources ADD COLUMN source_hostname VARCHAR(253) NULL;

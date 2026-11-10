ALTER TABLE origins ADD COLUMN source_ip VARCHAR(45) NULL;

CREATE TABLE IF NOT EXISTS managed_sources (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    domain_id BIGINT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    zone_id VARCHAR(64) NOT NULL,
    hostname VARCHAR(253) NOT NULL,
    source_ip VARCHAR(45) NOT NULL,
    record_id VARCHAR(64) NULL,
    state VARCHAR(24) NOT NULL DEFAULT 'planned',
    after_json MEDIUMTEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY managed_source (domain_id,hostname),
    FOREIGN KEY (domain_id) REFERENCES domains(id),
    FOREIGN KEY (account_id) REFERENCES accounts(id)
);

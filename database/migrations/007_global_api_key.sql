ALTER TABLE accounts ADD COLUMN auth_type VARCHAR(20) NOT NULL DEFAULT 'api_token';

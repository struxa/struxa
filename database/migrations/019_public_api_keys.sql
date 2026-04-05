-- Per-client API keys for /api/v1 (hashed secrets; scopes JSON).

CREATE TABLE IF NOT EXISTS cms_public_api_keys (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  prefix VARCHAR(16) NOT NULL,
  key_hash VARCHAR(255) NOT NULL,
  scopes_json JSON NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at TIMESTAMP NULL DEFAULT NULL,
  revoked_at TIMESTAMP NULL DEFAULT NULL,
  UNIQUE KEY uq_cms_public_api_keys_prefix (prefix),
  KEY idx_cms_public_api_keys_revoked (revoked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- System-level provider keys shown in admin System > API keys.

CREATE TABLE IF NOT EXISTS cms_system_api_keys (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  provider VARCHAR(60) NOT NULL DEFAULT 'custom',
  key_name VARCHAR(160) NOT NULL,
  key_value VARCHAR(1024) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_system_api_key_provider_name (provider, key_name),
  KEY idx_system_api_key_provider (provider)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

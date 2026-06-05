-- Branded short links for outbound URLs (Admin → Analytics → External links).

CREATE TABLE IF NOT EXISTS cms_short_links (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(12) NOT NULL COMMENT 'URL-safe slug, lowercase a-z0-9',
  destination_url VARCHAR(2048) NOT NULL,
  label VARCHAR(255) NULL COMMENT 'Optional staff note',
  clicks INT UNSIGNED NOT NULL DEFAULT 0,
  created_by INT NULL COMMENT 'cms_users.id',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_short_link_code (code),
  KEY idx_short_link_clicks (clicks DESC),
  KEY idx_short_link_created (created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO cms_settings (setting_key, setting_value, autoload) VALUES
('short_link_enabled', '1', 1),
('short_link_prefix', 'go', 1),
('short_link_root_mode', '0', 1)
ON DUPLICATE KEY UPDATE setting_key = VALUES(setting_key);

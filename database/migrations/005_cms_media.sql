-- Stage 4: media library + optional branding references in settings.

CREATE TABLE IF NOT EXISTS cms_media (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  filename VARCHAR(255) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  mime_type VARCHAR(127) NOT NULL,
  extension VARCHAR(16) NOT NULL,
  file_size INT UNSIGNED NOT NULL,
  path VARCHAR(500) NOT NULL,
  alt_text VARCHAR(255) NULL,
  title VARCHAR(255) NULL,
  caption MEDIUMTEXT NULL,
  width INT UNSIGNED NULL,
  height INT UNSIGNED NULL,
  uploaded_by INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_cms_media_created (created_at),
  KEY idx_cms_media_filename (filename(64)),
  KEY idx_cms_media_mime (mime_type(32)),
  CONSTRAINT fk_cms_media_uploader FOREIGN KEY (uploaded_by) REFERENCES cms_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO cms_settings (setting_key, setting_value, autoload) VALUES
('logo_media_id', '', 1),
('favicon_media_id', '', 1)
ON DUPLICATE KEY UPDATE
  setting_value = VALUES(setting_value),
  autoload = VALUES(autoload);

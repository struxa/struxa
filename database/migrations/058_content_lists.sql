-- Saved content listings (Views-lite): filters, sort, public page / API / Twig.

CREATE TABLE IF NOT EXISTS cms_content_lists (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  slug VARCHAR(64) NOT NULL,
  description MEDIUMTEXT NULL,
  content_type_id INT UNSIGNED NOT NULL,
  definition_json MEDIUMTEXT NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  expose_public_page TINYINT(1) NOT NULL DEFAULT 0,
  expose_api TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cms_content_lists_slug (slug),
  KEY idx_cms_content_lists_type (content_type_id),
  KEY idx_cms_content_lists_active (is_active, expose_public_page),
  CONSTRAINT fk_cms_content_lists_type FOREIGN KEY (content_type_id) REFERENCES cms_content_types (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

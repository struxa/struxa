-- Stage 5: content types, custom fields, structured entries.

CREATE TABLE IF NOT EXISTS cms_content_types (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  slug VARCHAR(64) NOT NULL,
  icon VARCHAR(64) NULL,
  description MEDIUMTEXT NULL,
  has_public_route TINYINT(1) NOT NULL DEFAULT 0,
  supports_seo TINYINT(1) NOT NULL DEFAULT 0,
  supports_featured_image TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cms_content_types_slug (slug),
  KEY idx_cms_content_types_public (has_public_route, slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_content_fields (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  content_type_id INT UNSIGNED NOT NULL,
  label VARCHAR(191) NOT NULL,
  field_key VARCHAR(64) NOT NULL,
  field_type ENUM(
    'text', 'textarea', 'richtext', 'number', 'boolean', 'select', 'image', 'date', 'url'
  ) NOT NULL,
  placeholder VARCHAR(255) NULL,
  help_text MEDIUMTEXT NULL,
  is_required TINYINT(1) NOT NULL DEFAULT 0,
  default_value MEDIUMTEXT NULL,
  options_json MEDIUMTEXT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cms_content_fields_type_key (content_type_id, field_key),
  KEY idx_cms_content_fields_type_sort (content_type_id, sort_order),
  CONSTRAINT fk_cms_content_fields_type FOREIGN KEY (content_type_id) REFERENCES cms_content_types (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_content_entries (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  content_type_id INT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL,
  slug VARCHAR(191) NOT NULL,
  status ENUM('draft', 'published') NOT NULL DEFAULT 'draft',
  featured_image_id INT UNSIGNED NULL,
  seo_title VARCHAR(255) NULL,
  seo_description VARCHAR(500) NULL,
  published_at TIMESTAMP NULL DEFAULT NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cms_content_entries_type_slug (content_type_id, slug),
  KEY idx_cms_content_entries_type_status (content_type_id, status),
  KEY idx_cms_content_entries_published (content_type_id, status, slug),
  CONSTRAINT fk_cms_content_entries_type FOREIGN KEY (content_type_id) REFERENCES cms_content_types (id) ON DELETE CASCADE,
  CONSTRAINT fk_cms_content_entries_featured FOREIGN KEY (featured_image_id) REFERENCES cms_media (id) ON DELETE SET NULL,
  CONSTRAINT fk_cms_content_entries_author FOREIGN KEY (created_by) REFERENCES cms_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_content_entry_values (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  content_entry_id INT UNSIGNED NOT NULL,
  field_id INT UNSIGNED NOT NULL,
  value_longtext MEDIUMTEXT NULL,
  UNIQUE KEY uq_cms_entry_values_entry_field (content_entry_id, field_id),
  KEY idx_cms_entry_values_field (field_id),
  CONSTRAINT fk_cms_entry_values_entry FOREIGN KEY (content_entry_id) REFERENCES cms_content_entries (id) ON DELETE CASCADE,
  CONSTRAINT fk_cms_entry_values_field FOREIGN KEY (field_id) REFERENCES cms_content_fields (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

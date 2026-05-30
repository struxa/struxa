-- Block builder sections for content entries (mirrors cms_page_sections).

CREATE TABLE IF NOT EXISTS cms_content_entry_sections (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  content_entry_id INT UNSIGNED NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  section_key VARCHAR(64) NOT NULL,
  data_json MEDIUMTEXT NOT NULL,
  options_json MEDIUMTEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_entry_sections_entry_sort (content_entry_id, sort_order),
  CONSTRAINT fk_entry_sections_entry FOREIGN KEY (content_entry_id) REFERENCES cms_content_entries (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE cms_content_types
  ADD COLUMN supports_block_builder TINYINT(1) NOT NULL DEFAULT 1 AFTER supports_featured_image;

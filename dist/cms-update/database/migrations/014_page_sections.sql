-- V2: ordered section instances per CMS page (visual builder).

CREATE TABLE IF NOT EXISTS cms_page_sections (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  page_id INT UNSIGNED NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  section_key VARCHAR(64) NOT NULL,
  data_json MEDIUMTEXT NOT NULL,
  options_json MEDIUMTEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_page_sections_page_sort (page_id, sort_order),
  CONSTRAINT fk_page_sections_page FOREIGN KEY (page_id) REFERENCES cms_pages (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stage 7: taxonomies, terms, entry assignments (CMS-wide grouping).

CREATE TABLE IF NOT EXISTS cms_taxonomies (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  content_type_id INT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL,
  slug VARCHAR(64) NOT NULL,
  description MEDIUMTEXT NULL,
  taxonomy_type ENUM('category', 'tag', 'custom') NOT NULL DEFAULT 'custom',
  is_hierarchical TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cms_taxonomies_type_slug (content_type_id, slug),
  KEY idx_cms_taxonomies_type (content_type_id),
  CONSTRAINT fk_cms_taxonomies_content_type FOREIGN KEY (content_type_id) REFERENCES cms_content_types (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_taxonomy_terms (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  taxonomy_id INT UNSIGNED NOT NULL,
  name VARCHAR(191) NOT NULL,
  slug VARCHAR(191) NOT NULL,
  description MEDIUMTEXT NULL,
  parent_id INT UNSIGNED NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cms_taxonomy_terms_tax_slug (taxonomy_id, slug),
  KEY idx_cms_taxonomy_terms_tax_sort (taxonomy_id, sort_order, id),
  KEY idx_cms_taxonomy_terms_parent (parent_id),
  CONSTRAINT fk_cms_taxonomy_terms_tax FOREIGN KEY (taxonomy_id) REFERENCES cms_taxonomies (id) ON DELETE CASCADE,
  CONSTRAINT fk_cms_taxonomy_terms_parent FOREIGN KEY (parent_id) REFERENCES cms_taxonomy_terms (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_content_entry_taxonomy_terms (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  content_entry_id INT UNSIGNED NOT NULL,
  taxonomy_term_id INT UNSIGNED NOT NULL,
  UNIQUE KEY uq_cms_entry_tax_term (content_entry_id, taxonomy_term_id),
  KEY idx_cms_entry_tax_term_entry (content_entry_id),
  KEY idx_cms_entry_tax_term_term (taxonomy_term_id),
  CONSTRAINT fk_cms_entry_tax_term_entry FOREIGN KEY (content_entry_id) REFERENCES cms_content_entries (id) ON DELETE CASCADE,
  CONSTRAINT fk_cms_entry_tax_term_term FOREIGN KEY (taxonomy_term_id) REFERENCES cms_taxonomy_terms (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

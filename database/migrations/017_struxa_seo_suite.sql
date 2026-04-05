-- Struxa Native SEO Suite: extended SEO fields, redirects, 404 log.
-- Idempotent (information_schema checks).

SET @db := DATABASE();

-- ---------- cms_pages ----------
SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_pages ADD COLUMN canonical_url VARCHAR(2048) NULL AFTER featured_image_id', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='cms_pages' AND COLUMN_NAME='canonical_url');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_pages ADD COLUMN seo_noindex TINYINT(1) NOT NULL DEFAULT 0 AFTER canonical_url', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='cms_pages' AND COLUMN_NAME='seo_noindex');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_pages ADD COLUMN og_title VARCHAR(255) NULL AFTER seo_noindex', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='cms_pages' AND COLUMN_NAME='og_title');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_pages ADD COLUMN og_description VARCHAR(500) NULL AFTER og_title', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='cms_pages' AND COLUMN_NAME='og_description');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_pages ADD COLUMN og_image_id INT UNSIGNED NULL AFTER og_description', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='cms_pages' AND COLUMN_NAME='og_image_id');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_pages ADD COLUMN twitter_title VARCHAR(255) NULL AFTER og_image_id', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='cms_pages' AND COLUMN_NAME='twitter_title');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_pages ADD COLUMN twitter_description VARCHAR(500) NULL AFTER twitter_title', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='cms_pages' AND COLUMN_NAME='twitter_description');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_pages ADD COLUMN twitter_image_id INT UNSIGNED NULL AFTER twitter_description', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='cms_pages' AND COLUMN_NAME='twitter_image_id');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_pages ADD COLUMN schema_json LONGTEXT NULL AFTER twitter_image_id', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='cms_pages' AND COLUMN_NAME='schema_json');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_pages ADD CONSTRAINT fk_cms_pages_og_image FOREIGN KEY (og_image_id) REFERENCES cms_media (id) ON DELETE SET NULL', 'SELECT 1') FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=@db AND TABLE_NAME='cms_pages' AND CONSTRAINT_NAME='fk_cms_pages_og_image');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_pages ADD CONSTRAINT fk_cms_pages_twitter_image FOREIGN KEY (twitter_image_id) REFERENCES cms_media (id) ON DELETE SET NULL', 'SELECT 1') FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=@db AND TABLE_NAME='cms_pages' AND CONSTRAINT_NAME='fk_cms_pages_twitter_image');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

-- ---------- cms_page_revisions (no FK on media ids for restore flexibility) ----------
SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_page_revisions ADD COLUMN canonical_url VARCHAR(2048) NULL AFTER featured_image_id', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='cms_page_revisions' AND COLUMN_NAME='canonical_url');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_page_revisions ADD COLUMN seo_noindex TINYINT(1) NOT NULL DEFAULT 0 AFTER canonical_url', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='cms_page_revisions' AND COLUMN_NAME='seo_noindex');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_page_revisions ADD COLUMN og_title VARCHAR(255) NULL AFTER seo_noindex', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='cms_page_revisions' AND COLUMN_NAME='og_title');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_page_revisions ADD COLUMN og_description VARCHAR(500) NULL AFTER og_title', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='cms_page_revisions' AND COLUMN_NAME='og_description');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_page_revisions ADD COLUMN og_image_id INT UNSIGNED NULL AFTER og_description', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='cms_page_revisions' AND COLUMN_NAME='og_image_id');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_page_revisions ADD COLUMN twitter_title VARCHAR(255) NULL AFTER og_image_id', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='cms_page_revisions' AND COLUMN_NAME='twitter_title');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_page_revisions ADD COLUMN twitter_description VARCHAR(500) NULL AFTER twitter_title', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='cms_page_revisions' AND COLUMN_NAME='twitter_description');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_page_revisions ADD COLUMN twitter_image_id INT UNSIGNED NULL AFTER twitter_description', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='cms_page_revisions' AND COLUMN_NAME='twitter_image_id');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_page_revisions ADD COLUMN schema_json LONGTEXT NULL AFTER twitter_image_id', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='cms_page_revisions' AND COLUMN_NAME='schema_json');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

-- ---------- cms_content_entries ----------
SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_content_entries ADD COLUMN canonical_url VARCHAR(2048) NULL AFTER seo_description', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='cms_content_entries' AND COLUMN_NAME='canonical_url');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_content_entries ADD COLUMN seo_noindex TINYINT(1) NOT NULL DEFAULT 0 AFTER canonical_url', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='cms_content_entries' AND COLUMN_NAME='seo_noindex');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_content_entries ADD COLUMN og_title VARCHAR(255) NULL AFTER seo_noindex', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='cms_content_entries' AND COLUMN_NAME='og_title');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_content_entries ADD COLUMN og_description VARCHAR(500) NULL AFTER og_title', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='cms_content_entries' AND COLUMN_NAME='og_description');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_content_entries ADD COLUMN og_image_id INT UNSIGNED NULL AFTER og_description', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='cms_content_entries' AND COLUMN_NAME='og_image_id');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_content_entries ADD COLUMN twitter_title VARCHAR(255) NULL AFTER og_image_id', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='cms_content_entries' AND COLUMN_NAME='twitter_title');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_content_entries ADD COLUMN twitter_description VARCHAR(500) NULL AFTER twitter_title', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='cms_content_entries' AND COLUMN_NAME='twitter_description');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_content_entries ADD COLUMN twitter_image_id INT UNSIGNED NULL AFTER twitter_description', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='cms_content_entries' AND COLUMN_NAME='twitter_image_id');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_content_entries ADD COLUMN schema_json LONGTEXT NULL AFTER twitter_image_id', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='cms_content_entries' AND COLUMN_NAME='schema_json');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_content_entries ADD CONSTRAINT fk_cms_content_entries_og_image FOREIGN KEY (og_image_id) REFERENCES cms_media (id) ON DELETE SET NULL', 'SELECT 1') FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=@db AND TABLE_NAME='cms_content_entries' AND CONSTRAINT_NAME='fk_cms_content_entries_og_image');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_content_entries ADD CONSTRAINT fk_cms_content_entries_twitter_image FOREIGN KEY (twitter_image_id) REFERENCES cms_media (id) ON DELETE SET NULL', 'SELECT 1') FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=@db AND TABLE_NAME='cms_content_entries' AND CONSTRAINT_NAME='fk_cms_content_entries_twitter_image');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

-- ---------- cms_taxonomy_terms ----------
SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_taxonomy_terms ADD COLUMN seo_title VARCHAR(255) NULL AFTER description', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='cms_taxonomy_terms' AND COLUMN_NAME='seo_title');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_taxonomy_terms ADD COLUMN seo_description VARCHAR(500) NULL AFTER seo_title', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='cms_taxonomy_terms' AND COLUMN_NAME='seo_description');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_taxonomy_terms ADD COLUMN canonical_url VARCHAR(2048) NULL AFTER seo_description', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='cms_taxonomy_terms' AND COLUMN_NAME='canonical_url');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_taxonomy_terms ADD COLUMN seo_noindex TINYINT(1) NOT NULL DEFAULT 0 AFTER canonical_url', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='cms_taxonomy_terms' AND COLUMN_NAME='seo_noindex');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_taxonomy_terms ADD COLUMN og_title VARCHAR(255) NULL AFTER seo_noindex', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='cms_taxonomy_terms' AND COLUMN_NAME='og_title');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_taxonomy_terms ADD COLUMN og_description VARCHAR(500) NULL AFTER og_title', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='cms_taxonomy_terms' AND COLUMN_NAME='og_description');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_taxonomy_terms ADD COLUMN og_image_id INT UNSIGNED NULL AFTER og_description', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='cms_taxonomy_terms' AND COLUMN_NAME='og_image_id');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_taxonomy_terms ADD COLUMN twitter_title VARCHAR(255) NULL AFTER og_image_id', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='cms_taxonomy_terms' AND COLUMN_NAME='twitter_title');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_taxonomy_terms ADD COLUMN twitter_description VARCHAR(500) NULL AFTER twitter_title', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='cms_taxonomy_terms' AND COLUMN_NAME='twitter_description');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_taxonomy_terms ADD COLUMN twitter_image_id INT UNSIGNED NULL AFTER twitter_description', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='cms_taxonomy_terms' AND COLUMN_NAME='twitter_image_id');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_taxonomy_terms ADD COLUMN schema_json LONGTEXT NULL AFTER twitter_image_id', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='cms_taxonomy_terms' AND COLUMN_NAME='schema_json');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_taxonomy_terms ADD CONSTRAINT fk_cms_taxonomy_terms_og_image FOREIGN KEY (og_image_id) REFERENCES cms_media (id) ON DELETE SET NULL', 'SELECT 1') FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=@db AND TABLE_NAME='cms_taxonomy_terms' AND CONSTRAINT_NAME='fk_cms_taxonomy_terms_og_image');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_taxonomy_terms ADD CONSTRAINT fk_cms_taxonomy_terms_twitter_image FOREIGN KEY (twitter_image_id) REFERENCES cms_media (id) ON DELETE SET NULL', 'SELECT 1') FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=@db AND TABLE_NAME='cms_taxonomy_terms' AND CONSTRAINT_NAME='fk_cms_taxonomy_terms_twitter_image');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

-- ---------- redirects ----------
CREATE TABLE IF NOT EXISTS cms_redirects (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  from_path VARCHAR(1024) NOT NULL,
  to_url VARCHAR(2048) NOT NULL,
  status_code SMALLINT UNSIGNED NOT NULL DEFAULT 301,
  hit_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cms_redirects_from (from_path(512)),
  KEY idx_cms_redirects_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- 404 log ----------
CREATE TABLE IF NOT EXISTS cms_not_found_logs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  path VARCHAR(2048) NOT NULL,
  referer VARCHAR(2048) NULL,
  hit_count INT UNSIGNED NOT NULL DEFAULT 1,
  last_seen_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_not_found_path (path(512)),
  KEY idx_not_found_hits (hit_count DESC),
  KEY idx_not_found_last (last_seen_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

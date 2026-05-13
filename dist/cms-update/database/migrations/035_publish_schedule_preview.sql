-- Scheduled publish/unpublish, page published_at, shareable preview tokens, page revision schedule columns.
-- Idempotent (information_schema checks).

SET @db := DATABASE();

-- ---------- cms_pages ----------
SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_pages ADD COLUMN published_at TIMESTAMP NULL DEFAULT NULL AFTER updated_at', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='cms_pages' AND COLUMN_NAME='published_at');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_pages ADD COLUMN scheduled_publish_at TIMESTAMP NULL DEFAULT NULL AFTER published_at', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='cms_pages' AND COLUMN_NAME='scheduled_publish_at');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_pages ADD COLUMN scheduled_unpublish_at TIMESTAMP NULL DEFAULT NULL AFTER scheduled_publish_at', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='cms_pages' AND COLUMN_NAME='scheduled_unpublish_at');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

-- Backfill: treat existing published pages as already live
UPDATE cms_pages SET published_at = COALESCE(published_at, updated_at, created_at)
  WHERE status = 'published' AND published_at IS NULL;

-- ---------- cms_page_revisions ----------
SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_page_revisions ADD COLUMN published_at TIMESTAMP NULL DEFAULT NULL AFTER status', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='cms_page_revisions' AND COLUMN_NAME='published_at');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_page_revisions ADD COLUMN scheduled_publish_at TIMESTAMP NULL DEFAULT NULL AFTER published_at', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='cms_page_revisions' AND COLUMN_NAME='scheduled_publish_at');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_page_revisions ADD COLUMN scheduled_unpublish_at TIMESTAMP NULL DEFAULT NULL AFTER scheduled_publish_at', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='cms_page_revisions' AND COLUMN_NAME='scheduled_unpublish_at');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

-- ---------- cms_content_entries ----------
SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_content_entries ADD COLUMN scheduled_publish_at TIMESTAMP NULL DEFAULT NULL AFTER published_at', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='cms_content_entries' AND COLUMN_NAME='scheduled_publish_at');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_content_entries ADD COLUMN scheduled_unpublish_at TIMESTAMP NULL DEFAULT NULL AFTER scheduled_publish_at', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='cms_content_entries' AND COLUMN_NAME='scheduled_unpublish_at');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

-- ---------- cms_preview_tokens ----------
CREATE TABLE IF NOT EXISTS cms_preview_tokens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  token_hash CHAR(64) NOT NULL,
  subject_type ENUM('page', 'content_entry') NOT NULL,
  subject_id INT UNSIGNED NOT NULL,
  created_by INT UNSIGNED NULL,
  expires_at DATETIME NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cms_preview_tokens_hash (token_hash),
  KEY idx_cms_preview_tokens_expires (expires_at),
  KEY idx_cms_preview_tokens_subject (subject_type, subject_id),
  CONSTRAINT fk_cms_preview_tokens_user FOREIGN KEY (created_by) REFERENCES cms_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

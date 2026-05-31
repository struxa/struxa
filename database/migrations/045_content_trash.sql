-- Soft delete (trash) for pages, entries, and media.

SET @db := DATABASE();

-- ---------- cms_pages ----------
SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_pages ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL AFTER updated_at', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='cms_pages' AND COLUMN_NAME='deleted_at');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_pages ADD COLUMN deleted_by INT UNSIGNED NULL DEFAULT NULL AFTER deleted_at', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='cms_pages' AND COLUMN_NAME='deleted_by');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_pages ADD KEY idx_cms_pages_trash (deleted_at)', 'SELECT 1') FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='cms_pages' AND INDEX_NAME='idx_cms_pages_trash');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------- cms_content_entries ----------
SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_content_entries ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL AFTER updated_at', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='cms_content_entries' AND COLUMN_NAME='deleted_at');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_content_entries ADD COLUMN deleted_by INT UNSIGNED NULL DEFAULT NULL AFTER deleted_at', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='cms_content_entries' AND COLUMN_NAME='deleted_by');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_content_entries ADD KEY idx_cms_content_entries_trash (deleted_at)', 'SELECT 1') FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='cms_content_entries' AND INDEX_NAME='idx_cms_content_entries_trash');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------- cms_media ----------
SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_media ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL AFTER updated_at', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='cms_media' AND COLUMN_NAME='deleted_at');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_media ADD COLUMN deleted_by INT UNSIGNED NULL DEFAULT NULL AFTER deleted_at', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='cms_media' AND COLUMN_NAME='deleted_by');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_media ADD KEY idx_cms_media_trash (deleted_at)', 'SELECT 1') FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='cms_media' AND INDEX_NAME='idx_cms_media_trash');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

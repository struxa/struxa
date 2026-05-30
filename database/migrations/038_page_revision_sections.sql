-- Store page builder block snapshots on each revision.
-- Idempotent (information_schema checks).

SET @db := DATABASE();

SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_page_revisions ADD COLUMN sections_json LONGTEXT NULL DEFAULT NULL AFTER content', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='cms_page_revisions' AND COLUMN_NAME='sections_json');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

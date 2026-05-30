-- Yoast-style SEO analysis: focus keyphrase on pages, entries, terms, and page revisions.
-- Idempotent (information_schema checks).

SET @db := DATABASE();

-- ---------- cms_pages ----------
SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_pages ADD COLUMN focus_keyphrase VARCHAR(120) NULL AFTER seo_description', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='cms_pages' AND COLUMN_NAME='focus_keyphrase');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

-- ---------- cms_page_revisions ----------
SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_page_revisions ADD COLUMN focus_keyphrase VARCHAR(120) NULL AFTER seo_description', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='cms_page_revisions' AND COLUMN_NAME='focus_keyphrase');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

-- ---------- cms_content_entries ----------
SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_content_entries ADD COLUMN focus_keyphrase VARCHAR(120) NULL AFTER seo_description', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='cms_content_entries' AND COLUMN_NAME='focus_keyphrase');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

-- ---------- cms_taxonomy_terms ----------
SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_taxonomy_terms ADD COLUMN focus_keyphrase VARCHAR(120) NULL AFTER seo_description', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='cms_taxonomy_terms' AND COLUMN_NAME='focus_keyphrase');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

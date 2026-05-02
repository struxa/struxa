-- Page SEO + lightweight tags (JSON array of slug strings). Structured "posts" still use content types + taxonomies.
-- Idempotent: safe if columns already exist (e.g. partial deploy or manual DDL).

SET @db := DATABASE();

-- cms_pages
SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE cms_pages ADD COLUMN seo_title VARCHAR(255) NULL AFTER slug',
    'SELECT 1'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'cms_pages' AND COLUMN_NAME = 'seo_title'
);
PREPARE _s FROM @sql;
EXECUTE _s;
DEALLOCATE PREPARE _s;

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE cms_pages ADD COLUMN seo_description VARCHAR(500) NULL AFTER seo_title',
    'SELECT 1'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'cms_pages' AND COLUMN_NAME = 'seo_description'
);
PREPARE _s FROM @sql;
EXECUTE _s;
DEALLOCATE PREPARE _s;

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE cms_pages ADD COLUMN tags_json JSON NULL AFTER seo_description',
    'SELECT 1'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'cms_pages' AND COLUMN_NAME = 'tags_json'
);
PREPARE _s FROM @sql;
EXECUTE _s;
DEALLOCATE PREPARE _s;

-- cms_page_revisions
SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE cms_page_revisions ADD COLUMN seo_title VARCHAR(255) NULL AFTER slug',
    'SELECT 1'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'cms_page_revisions' AND COLUMN_NAME = 'seo_title'
);
PREPARE _s FROM @sql;
EXECUTE _s;
DEALLOCATE PREPARE _s;

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE cms_page_revisions ADD COLUMN seo_description VARCHAR(500) NULL AFTER seo_title',
    'SELECT 1'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'cms_page_revisions' AND COLUMN_NAME = 'seo_description'
);
PREPARE _s FROM @sql;
EXECUTE _s;
DEALLOCATE PREPARE _s;

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE cms_page_revisions ADD COLUMN tags_json JSON NULL AFTER seo_description',
    'SELECT 1'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'cms_page_revisions' AND COLUMN_NAME = 'tags_json'
);
PREPARE _s FROM @sql;
EXECUTE _s;
DEALLOCATE PREPARE _s;

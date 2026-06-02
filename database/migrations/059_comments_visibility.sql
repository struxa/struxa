-- Global + per-page / per-content-type comment visibility toggles.

SET @db := DATABASE();

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE cms_pages ADD COLUMN comments_disabled TINYINT(1) NOT NULL DEFAULT 0 AFTER status',
    'SELECT 1'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'cms_pages' AND COLUMN_NAME = 'comments_disabled'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE cms_content_types ADD COLUMN comments_disabled TINYINT(1) NOT NULL DEFAULT 0 AFTER supports_block_builder',
    'SELECT 1'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'cms_content_types' AND COLUMN_NAME = 'comments_disabled'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT INTO cms_settings (setting_key, setting_value, autoload)
SELECT 'comments_enabled', '1', 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM cms_settings WHERE setting_key = 'comments_enabled');

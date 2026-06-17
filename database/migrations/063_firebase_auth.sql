-- Firebase Authentication: link CMS users to Firebase UIDs.

SET @db := DATABASE();

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE cms_users ADD COLUMN firebase_uid VARCHAR(128) NULL DEFAULT NULL AFTER phpauth_user_id',
    'SELECT 1'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'cms_users' AND COLUMN_NAME = 'firebase_uid'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE cms_users ADD UNIQUE KEY uq_cms_users_firebase_uid (firebase_uid)',
    'SELECT 1'
  )
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'cms_users' AND INDEX_NAME = 'uq_cms_users_firebase_uid'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

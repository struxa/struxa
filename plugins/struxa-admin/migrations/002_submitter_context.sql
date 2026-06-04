-- Capture account username, IP, and user agent on catalog submissions (login-gated form).
-- Idempotent (information_schema checks).

SET @db := DATABASE();

SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_struxa_catalog_submissions ADD COLUMN submitter_username VARCHAR(191) NOT NULL DEFAULT '''' AFTER submitter_user_id', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='cms_struxa_catalog_submissions' AND COLUMN_NAME='submitter_username');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_struxa_catalog_submissions ADD COLUMN submitter_ip VARCHAR(45) NOT NULL DEFAULT '''' AFTER submitter_username', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='cms_struxa_catalog_submissions' AND COLUMN_NAME='submitter_ip');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @sql := (SELECT IF(COUNT(*)=0, 'ALTER TABLE cms_struxa_catalog_submissions ADD COLUMN submitter_user_agent VARCHAR(500) NULL AFTER submitter_ip', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='cms_struxa_catalog_submissions' AND COLUMN_NAME='submitter_user_agent');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

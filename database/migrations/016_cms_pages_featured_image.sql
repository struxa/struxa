-- Featured image (media library) for CMS pages + revisions.
-- Idempotent.

SET @db := DATABASE();

-- cms_pages.featured_image_id
SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE cms_pages ADD COLUMN featured_image_id INT UNSIGNED NULL AFTER tags_json',
    'SELECT 1'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'cms_pages' AND COLUMN_NAME = 'featured_image_id'
);
PREPARE _s FROM @sql;
EXECUTE _s;
DEALLOCATE PREPARE _s;

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE cms_pages ADD CONSTRAINT fk_cms_pages_featured_image FOREIGN KEY (featured_image_id) REFERENCES cms_media (id) ON DELETE SET NULL',
    'SELECT 1'
  )
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = @db AND TABLE_NAME = 'cms_pages' AND CONSTRAINT_NAME = 'fk_cms_pages_featured_image'
);
PREPARE _s FROM @sql;
EXECUTE _s;
DEALLOCATE PREPARE _s;

-- cms_page_revisions.featured_image_id
SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE cms_page_revisions ADD COLUMN featured_image_id INT UNSIGNED NULL AFTER tags_json',
    'SELECT 1'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'cms_page_revisions' AND COLUMN_NAME = 'featured_image_id'
);
PREPARE _s FROM @sql;
EXECUTE _s;
DEALLOCATE PREPARE _s;

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE cms_page_revisions ADD CONSTRAINT fk_cms_page_revisions_featured_image FOREIGN KEY (featured_image_id) REFERENCES cms_media (id) ON DELETE SET NULL',
    'SELECT 1'
  )
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = @db AND TABLE_NAME = 'cms_page_revisions' AND CONSTRAINT_NAME = 'fk_cms_page_revisions_featured_image'
);
PREPARE _s FROM @sql;
EXECUTE _s;
DEALLOCATE PREPARE _s;

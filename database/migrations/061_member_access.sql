-- Members-only pages and content entries with optional role restrictions.

SET @db := DATABASE();

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE cms_pages ADD COLUMN members_only TINYINT(1) NOT NULL DEFAULT 0 AFTER comments_disabled',
    'SELECT 1'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'cms_pages' AND COLUMN_NAME = 'members_only'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE cms_content_entries ADD COLUMN members_only TINYINT(1) NOT NULL DEFAULT 0 AFTER status',
    'SELECT 1'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'cms_content_entries' AND COLUMN_NAME = 'members_only'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS cms_page_roles (
  page_id INT UNSIGNED NOT NULL,
  role_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (page_id, role_id),
  KEY idx_cms_page_roles_role (role_id),
  CONSTRAINT fk_cms_page_roles_page FOREIGN KEY (page_id) REFERENCES cms_pages (id) ON DELETE CASCADE,
  CONSTRAINT fk_cms_page_roles_role FOREIGN KEY (role_id) REFERENCES cms_roles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_content_entry_roles (
  content_entry_id INT UNSIGNED NOT NULL,
  role_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (content_entry_id, role_id),
  KEY idx_cms_content_entry_roles_role (role_id),
  CONSTRAINT fk_cms_content_entry_roles_entry FOREIGN KEY (content_entry_id) REFERENCES cms_content_entries (id) ON DELETE CASCADE,
  CONSTRAINT fk_cms_content_entry_roles_role FOREIGN KEY (role_id) REFERENCES cms_roles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO cms_roles (name, slug, description, is_system) VALUES
('Member', 'member', 'Registered front-end account.', 1)
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description);

INSERT IGNORE INTO cms_role_user (role_id, user_id)
SELECT r.id, u.id
FROM cms_users u
CROSS JOIN cms_roles r
WHERE r.slug = 'member' AND u.role = 'subscriber';

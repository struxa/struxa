-- Stage 9: roles, permissions, workflow statuses, revisions, activity log

-- ---------------------------------------------------------------------------
-- RBAC
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS cms_roles (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  slug VARCHAR(64) NOT NULL,
  description MEDIUMTEXT NULL,
  is_system TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cms_roles_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_permissions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  slug VARCHAR(80) NOT NULL,
  description MEDIUMTEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cms_permissions_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_role_user (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cms_role_user (role_id, user_id),
  KEY idx_cms_role_user_user (user_id),
  CONSTRAINT fk_cms_role_user_role FOREIGN KEY (role_id) REFERENCES cms_roles (id) ON DELETE CASCADE,
  CONSTRAINT fk_cms_role_user_user FOREIGN KEY (user_id) REFERENCES cms_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_permission_role (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  permission_id INT UNSIGNED NOT NULL,
  role_id INT UNSIGNED NOT NULL,
  UNIQUE KEY uq_cms_permission_role (permission_id, role_id),
  CONSTRAINT fk_cms_perm_role_perm FOREIGN KEY (permission_id) REFERENCES cms_permissions (id) ON DELETE CASCADE,
  CONSTRAINT fk_cms_perm_role_role FOREIGN KEY (role_id) REFERENCES cms_roles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE cms_users
  ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER role;

-- Seed roles (system = protected from delete)
INSERT INTO cms_roles (name, slug, description, is_system) VALUES
('Super Admin', 'super_admin', 'Full system access.', 1),
('Admin', 'admin', 'Site administration without role management.', 1),
('Editor', 'editor', 'Content and structure; no system settings.', 1),
('Author', 'author', 'Create and edit drafts; limited publishing.', 1),
('Reviewer', 'reviewer', 'Review and approve workflow.', 1)
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description);

-- Seed permissions
INSERT INTO cms_permissions (name, slug, description) VALUES
('Access admin', 'access_admin', 'Sign in to the CMS panel.'),
('Manage users', 'manage_users', 'Create and edit CMS users.'),
('Manage roles', 'manage_roles', 'Edit roles and permission assignments.'),
('Manage settings', 'manage_settings', 'Site settings.'),
('Manage menus', 'manage_menus', 'Navigation menus.'),
('Manage media', 'manage_media', 'Media library.'),
('Manage themes', 'manage_themes', 'Themes.'),
('Manage plugins', 'manage_plugins', 'Plugins.'),
('Manage content types', 'manage_content_types', 'Content models and fields.'),
('Manage taxonomies', 'manage_taxonomies', 'Taxonomies and terms.'),
('Manage pages', 'manage_pages', 'Static pages.'),
('Create content', 'create_content', 'Create entries and drafts.'),
('Edit content', 'edit_content', 'Edit entries and pages.'),
('Delete content', 'delete_content', 'Delete entries and pages.'),
('Publish content', 'publish_content', 'Publish or archive.'),
('Review content', 'review_content', 'Submit for review, approve, send back.'),
('View activity log', 'view_activity', 'Read audit log.')
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description);

-- super_admin: all permissions
INSERT IGNORE INTO cms_permission_role (permission_id, role_id)
SELECT p.id, r.id FROM cms_permissions p
CROSS JOIN cms_roles r WHERE r.slug = 'super_admin';

-- admin: all except manage_roles
INSERT IGNORE INTO cms_permission_role (permission_id, role_id)
SELECT p.id, r.id FROM cms_permissions p
CROSS JOIN cms_roles r
WHERE r.slug = 'admin' AND p.slug <> 'manage_roles';

-- editor: content + structure, no system
INSERT IGNORE INTO cms_permission_role (permission_id, role_id)
SELECT p.id, r.id FROM cms_permissions p
CROSS JOIN cms_roles r
WHERE r.slug = 'editor' AND p.slug IN (
  'access_admin','manage_menus','manage_media','manage_content_types','manage_taxonomies','manage_pages',
  'create_content','edit_content','delete_content','publish_content','review_content','view_activity'
);

-- author: draft-focused
INSERT IGNORE INTO cms_permission_role (permission_id, role_id)
SELECT p.id, r.id FROM cms_permissions p
CROSS JOIN cms_roles r
WHERE r.slug = 'author' AND p.slug IN (
  'access_admin','manage_media','manage_pages','manage_content_types','create_content','edit_content'
);

-- reviewer
INSERT IGNORE INTO cms_permission_role (permission_id, role_id)
SELECT p.id, r.id FROM cms_permissions p
CROSS JOIN cms_roles r
WHERE r.slug = 'reviewer' AND p.slug IN (
  'access_admin','manage_pages','manage_media','manage_content_types','edit_content','review_content','publish_content','view_activity'
);

-- Map legacy cms_users.role to new roles
INSERT IGNORE INTO cms_role_user (role_id, user_id)
SELECT r.id, u.id FROM cms_users u
JOIN cms_roles r ON (
  (u.role = 'admin' AND r.slug = 'super_admin') OR
  (u.role = 'editor' AND r.slug = 'editor') OR
  (u.role = 'author' AND r.slug = 'author')
);

-- ---------------------------------------------------------------------------
-- Workflow: widen status enums
-- ---------------------------------------------------------------------------

ALTER TABLE cms_pages
  MODIFY COLUMN status ENUM('draft','in_review','approved','published','archived') NOT NULL DEFAULT 'draft';

ALTER TABLE cms_content_entries
  MODIFY COLUMN status ENUM('draft','in_review','approved','published','archived') NOT NULL DEFAULT 'draft';

ALTER TABLE cms_pages
  ADD COLUMN updated_by INT UNSIGNED NULL AFTER updated_at,
  ADD CONSTRAINT fk_cms_pages_updated_by FOREIGN KEY (updated_by) REFERENCES cms_users (id) ON DELETE SET NULL;

-- ---------------------------------------------------------------------------
-- Revisions
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS cms_page_revisions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  page_id INT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL,
  slug VARCHAR(191) NOT NULL,
  content MEDIUMTEXT NOT NULL,
  status VARCHAR(32) NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_cms_page_revisions_page (page_id, created_at),
  CONSTRAINT fk_cms_page_revisions_page FOREIGN KEY (page_id) REFERENCES cms_pages (id) ON DELETE CASCADE,
  CONSTRAINT fk_cms_page_revisions_user FOREIGN KEY (created_by) REFERENCES cms_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_content_entry_revisions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  content_entry_id INT UNSIGNED NOT NULL,
  snapshot_json MEDIUMTEXT NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_cms_entry_revisions_entry (content_entry_id, created_at),
  CONSTRAINT fk_cms_entry_revisions_entry FOREIGN KEY (content_entry_id) REFERENCES cms_content_entries (id) ON DELETE CASCADE,
  CONSTRAINT fk_cms_entry_revisions_user FOREIGN KEY (created_by) REFERENCES cms_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Activity log
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS cms_activity_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  event_type VARCHAR(80) NOT NULL,
  subject_type VARCHAR(80) NULL,
  subject_id INT UNSIGNED NULL,
  details_json MEDIUMTEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_cms_activity_created (created_at),
  KEY idx_cms_activity_user (user_id),
  KEY idx_cms_activity_subject (subject_type, subject_id),
  CONSTRAINT fk_cms_activity_user FOREIGN KEY (user_id) REFERENCES cms_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

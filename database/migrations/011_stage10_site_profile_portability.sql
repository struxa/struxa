-- Stage 10: site profile metadata + portability permission

CREATE TABLE IF NOT EXISTS cms_site_profile (
  id TINYINT UNSIGNED PRIMARY KEY DEFAULT 1,
  site_uuid CHAR(36) NOT NULL,
  project_name VARCHAR(200) NOT NULL DEFAULT '',
  environment_label VARCHAR(64) NULL,
  cms_version_installed VARCHAR(32) NOT NULL DEFAULT '',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO cms_site_profile (id, site_uuid, project_name, cms_version_installed)
VALUES (1, UUID(), 'My site', '1.0.0');

INSERT INTO cms_permissions (name, slug, description) VALUES
('Manage portability', 'manage_portability', 'Blueprints, structure import and export.')
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description);

INSERT IGNORE INTO cms_permission_role (permission_id, role_id)
SELECT p.id, r.id FROM cms_permissions p
CROSS JOIN cms_roles r
WHERE p.slug = 'manage_portability' AND r.slug IN ('super_admin', 'admin');

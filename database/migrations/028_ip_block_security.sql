-- Whole-site IP deny list (middleware) + manage_security permission

CREATE TABLE IF NOT EXISTS cms_ip_blocks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  pattern VARCHAR(128) NOT NULL COMMENT 'IPv4, IPv6, or IPv4 CIDR',
  note VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cms_ip_blocks_pattern (pattern)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO cms_permissions (name, slug, description) VALUES
('Manage security', 'manage_security', 'IP block list and related site security tools.')
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description);

INSERT IGNORE INTO cms_permission_role (permission_id, role_id)
SELECT p.id, r.id FROM cms_permissions p
CROSS JOIN cms_roles r
WHERE p.slug = 'manage_security' AND r.slug IN ('super_admin', 'admin');

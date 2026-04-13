-- Public comments (Disqus-style threads) with moderation-oriented defaults.

CREATE TABLE IF NOT EXISTS cms_comments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  thread_key VARCHAR(120) NOT NULL COMMENT 'page:{id} or entry:{id}',
  parent_id BIGINT UNSIGNED NULL,
  depth TINYINT UNSIGNED NOT NULL DEFAULT 0,
  status ENUM('pending','approved','rejected','spam') NOT NULL DEFAULT 'pending',
  author_name VARCHAR(120) NOT NULL,
  author_email_hash CHAR(64) NOT NULL COMMENT 'sha256(lower(trim(email)))',
  body TEXT NOT NULL,
  body_html MEDIUMTEXT NOT NULL,
  client_ip VARCHAR(45) NOT NULL,
  user_agent VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  approved_at TIMESTAMP NULL DEFAULT NULL,
  CONSTRAINT fk_cms_comments_parent
    FOREIGN KEY (parent_id) REFERENCES cms_comments(id)
    ON DELETE CASCADE,
  KEY idx_cms_comments_thread_status_created (thread_key, status, created_at),
  KEY idx_cms_comments_status_created (status, created_at),
  KEY idx_cms_comments_parent (parent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO cms_permissions (name, slug, description) VALUES
('Manage comments', 'manage_comments', 'Moderate and manage public comments.')
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description);

INSERT IGNORE INTO cms_permission_role (permission_id, role_id)
SELECT p.id, r.id FROM cms_permissions p
CROSS JOIN cms_roles r
WHERE p.slug = 'manage_comments' AND r.slug IN ('super_admin', 'admin');

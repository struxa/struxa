-- External link click tracking
--
-- Logs each user click on an outbound (different host) anchor on the storefront so
-- Admin → Site → External links can show top destinations, top source pages and recent clicks.
-- The beacon endpoint POST /track/external-link inserts rows here (see routes/public_external_link_tracking.php).

CREATE TABLE IF NOT EXISTS cms_external_link_clicks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  destination_url VARCHAR(2048) NOT NULL,
  destination_url_hash CHAR(64) NOT NULL COMMENT 'sha256 of trim+lower destination_url for indexed grouping',
  destination_host VARCHAR(255) NOT NULL,
  source_path VARCHAR(512) NOT NULL COMMENT 'pathname of the page that contained the link',
  source_url VARCHAR(2048) NULL COMMENT 'full source URL incl. query (no fragment)',
  referrer_external VARCHAR(2048) NULL COMMENT 'document.referrer when external (came from)',
  link_text VARCHAR(255) NULL COMMENT 'anchor text or aria-label, truncated',
  client_ip VARCHAR(45) NOT NULL,
  user_agent VARCHAR(512) NULL,
  user_id INT NULL COMMENT 'phpauth_users.id if signed in',
  clicked_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_elc_dest_hash (destination_url_hash),
  KEY idx_elc_host (destination_host),
  KEY idx_elc_source (source_path),
  KEY idx_elc_clicked_at (clicked_at DESC),
  KEY idx_elc_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO cms_permissions (name, slug, description) VALUES
('View link analytics', 'view_link_analytics', 'External link click analytics: top destinations, top source pages, recent clicks.')
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description);

INSERT IGNORE INTO cms_permission_role (permission_id, role_id)
SELECT p.id, r.id FROM cms_permissions p
CROSS JOIN cms_roles r
WHERE p.slug = 'view_link_analytics' AND r.slug IN ('super_admin', 'admin');

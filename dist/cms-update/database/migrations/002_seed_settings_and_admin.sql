-- Default autoloaded settings + link seeded PHPAuth admin to cms_users.

INSERT INTO cms_settings (setting_key, setting_value, autoload) VALUES
('site_name', 'struxapoint.com', 1),
('cms_panel_title', 'Struxa', 1),
('cms_panel_tagline', 'Ship content without the bloat.', 1),
('cms_theme_accent', 'violet', 1)
ON DUPLICATE KEY UPDATE
  setting_value = VALUES(setting_value),
  autoload = VALUES(autoload);

INSERT INTO cms_users (phpauth_user_id, email, display_name, role)
SELECT p.id, p.email, 'Administrator', 'admin'
FROM phpauth_users p
WHERE p.email = 'admin@example.com'
  AND NOT EXISTS (SELECT 1 FROM cms_users c WHERE c.phpauth_user_id = p.id)
LIMIT 1;

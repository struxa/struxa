-- Public site URL (canonical domain) editable in Admin → Site settings.
INSERT INTO cms_settings (setting_key, setting_value, autoload) VALUES
('site_url', '', 1)
ON DUPLICATE KEY UPDATE autoload = VALUES(autoload);

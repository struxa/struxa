-- Optional: seed default (Site settings form also persists this key on save).
INSERT INTO cms_settings (setting_key, setting_value, autoload) VALUES
  ('seo_external_links_nofollow', '0', 1)
ON DUPLICATE KEY UPDATE setting_key = setting_key;

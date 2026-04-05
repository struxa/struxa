-- Public cache toggles + TTL (read by CacheConfig; autoloaded).

INSERT INTO cms_settings (setting_key, setting_value, autoload) VALUES
('cache_public_enabled', '0', 1),
('cache_public_ttl_sec', '300', 1),
('assets_prefer_minified', '0', 1)
ON DUPLICATE KEY UPDATE
  autoload = VALUES(autoload);

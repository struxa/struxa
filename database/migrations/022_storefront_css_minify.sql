-- Optional: minify theme CSS when served (cached under storage/cache/theme-css-min). Toggle in Admin → Performance & cache.

INSERT INTO cms_settings (setting_key, setting_value, autoload) VALUES
('storefront_css_minify', '0', 1)
ON DUPLICATE KEY UPDATE
  autoload = VALUES(autoload);

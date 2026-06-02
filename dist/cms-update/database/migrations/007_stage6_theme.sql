-- Stage 6: active theme (filesystem themes; core templates remain fallbacks).

INSERT INTO cms_settings (setting_key, setting_value, autoload) VALUES
('active_theme', 'struxa-theme', 1)
ON DUPLICATE KEY UPDATE
  setting_value = VALUES(setting_value),
  autoload = VALUES(autoload);

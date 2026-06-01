-- Mobile app: which content types and app sections to expose in the client.

INSERT INTO cms_settings (setting_key, setting_value, autoload) VALUES
('mobile_app_content_slugs_json', '', 1),
('mobile_app_features_json', '', 1)
ON DUPLICATE KEY UPDATE
  autoload = VALUES(autoload);

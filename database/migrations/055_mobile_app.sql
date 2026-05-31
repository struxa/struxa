-- Mobile app bootstrap settings (autoloaded; read by MobileSettings + MobileBootstrapService).

INSERT INTO cms_settings (setting_key, setting_value, autoload) VALUES
('mobile_app_enabled', '1', 1),
('mobile_app_welcome_title', '', 1),
('mobile_app_welcome_message', '', 1),
('mobile_app_include_footer_nav', '0', 1),
('mobile_app_tabs_json', '', 1)
ON DUPLICATE KEY UPDATE
  autoload = VALUES(autoload);

-- OpenAI integration for admin "AI draft" content tool (autoloaded).

INSERT INTO cms_settings (setting_key, setting_value, autoload) VALUES
('openai_enabled', '0', 1),
('openai_api_key', '', 1),
('openai_model', 'gpt-4o-mini', 1)
ON DUPLICATE KEY UPDATE
  autoload = VALUES(autoload);

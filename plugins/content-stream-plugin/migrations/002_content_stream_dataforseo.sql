-- Optional: live keyword metrics (search volume, CPC, competition, difficulty, variations via DataForSEO).
-- Run after 001_content_stream_settings.sql when you want the keyword metrics button on the domain tool.

ALTER TABLE cms_plugin_content_stream_settings
  ADD COLUMN dataforseo_login VARCHAR(255) NULL DEFAULT NULL AFTER openai_model,
  ADD COLUMN dataforseo_password VARCHAR(512) NULL DEFAULT NULL AFTER dataforseo_login,
  ADD COLUMN keyword_location_code INT UNSIGNED NOT NULL DEFAULT 2840 AFTER dataforseo_password,
  ADD COLUMN keyword_language_code VARCHAR(8) NOT NULL DEFAULT 'en' AFTER keyword_location_code;

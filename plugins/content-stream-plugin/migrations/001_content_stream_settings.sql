-- Run once against your CMS database (plugin migrations are not auto-run by core).
CREATE TABLE IF NOT EXISTS cms_plugin_content_stream_settings (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
  openai_api_key VARCHAR(512) NULL,
  openai_organization VARCHAR(120) NULL,
  openai_model VARCHAR(80) NOT NULL DEFAULT 'gpt-4o-mini'
);

INSERT IGNORE INTO cms_plugin_content_stream_settings (id) VALUES (1);

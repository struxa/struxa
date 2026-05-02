-- Run once against your CMS database (plugin migrations are not auto-run by core).
CREATE TABLE IF NOT EXISTS cms_plugin_stripe_store_settings (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
  publishable_key VARCHAR(255) NULL,
  secret_key VARCHAR(512) NULL,
  webhook_secret VARCHAR(255) NULL,
  allowed_type_slugs VARCHAR(600) NOT NULL DEFAULT 'products',
  currency VARCHAR(12) NOT NULL DEFAULT 'usd',
  embed_enabled TINYINT(1) NOT NULL DEFAULT 1,
  button_label VARCHAR(80) NOT NULL DEFAULT 'Buy now'
);

INSERT IGNORE INTO cms_plugin_stripe_store_settings (id) VALUES (1);

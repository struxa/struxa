-- Sitemap.xml toggles (autoloaded; read by SitemapOptions + public robots.txt).

INSERT INTO cms_settings (setting_key, setting_value, autoload) VALUES
('sitemap_enabled', '1', 1),
('sitemap_include_pages', '1', 1),
('sitemap_include_entries', '1', 1),
('sitemap_include_taxonomy_archives', '1', 1)
ON DUPLICATE KEY UPDATE
  autoload = VALUES(autoload);

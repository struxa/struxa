-- Download counts for catalog packages (Browse catalog + direct ZIP fetches via tracked URL).

CREATE TABLE IF NOT EXISTS cms_struxa_catalog_download_stats (
    kind ENUM('plugin', 'theme') NOT NULL,
    slug VARCHAR(128) NOT NULL,
    download_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_download_at DATETIME NULL,
    PRIMARY KEY (kind, slug),
    KEY idx_catalog_downloads_count (download_count DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

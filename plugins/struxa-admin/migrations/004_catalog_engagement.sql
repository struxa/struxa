-- Public ratings (1–5) and comments on catalog packages.

CREATE TABLE IF NOT EXISTS cms_struxa_catalog_ratings (
    kind ENUM('plugin', 'theme') NOT NULL,
    slug VARCHAR(128) NOT NULL,
    cms_user_id INT UNSIGNED NOT NULL,
    rating TINYINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (kind, slug, cms_user_id),
    KEY idx_catalog_ratings_package (kind, slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_struxa_catalog_comments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    kind ENUM('plugin', 'theme') NOT NULL,
    slug VARCHAR(128) NOT NULL,
    cms_user_id INT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    status ENUM('visible', 'hidden') NOT NULL DEFAULT 'visible',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_catalog_comments_package (kind, slug, status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

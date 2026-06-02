-- Community catalog submissions (plugins + themes) for struxa-dist publishing.

CREATE TABLE IF NOT EXISTS cms_struxa_catalog_submissions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    kind ENUM('plugin', 'theme') NOT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    git_repo_url VARCHAR(500) NOT NULL,
    git_branch VARCHAR(120) NOT NULL DEFAULT 'main',
    slug VARCHAR(128) NOT NULL,
    name VARCHAR(255) NOT NULL,
    version VARCHAR(64) NOT NULL DEFAULT '1.0.0',
    description TEXT NULL,
    author VARCHAR(255) NOT NULL DEFAULT '',
    manifest_json JSON NULL,
    screenshot_path VARCHAR(500) NULL,
    submitter_name VARCHAR(191) NOT NULL DEFAULT '',
    submitter_email VARCHAR(191) NOT NULL DEFAULT '',
    submitter_user_id INT UNSIGNED NULL,
    reviewer_notes TEXT NULL,
    reviewed_by INT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    published_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_struxa_catalog_slug_kind (slug, kind),
    KEY idx_struxa_catalog_status (status, kind, created_at),
    KEY idx_struxa_catalog_pending (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

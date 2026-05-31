-- Reusable section patterns (named block snippets for the visual builder)

CREATE TABLE IF NOT EXISTS cms_section_patterns (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    slug VARCHAR(120) NOT NULL,
    description VARCHAR(500) NULL,
    host ENUM('page', 'content_entry', 'both') NOT NULL DEFAULT 'both',
    section_key VARCHAR(64) NOT NULL,
    data_json MEDIUMTEXT NOT NULL,
    options_json MEDIUMTEXT NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_section_patterns_slug (slug),
    KEY idx_section_patterns_host (host),
    KEY idx_section_patterns_section_key (section_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

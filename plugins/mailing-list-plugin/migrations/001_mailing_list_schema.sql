CREATE TABLE IF NOT EXISTS cms_mailing_list_lists (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(64) NOT NULL,
    name VARCHAR(191) NOT NULL,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mailing_list_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_mailing_list_subscribers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    list_id INT UNSIGNED NOT NULL,
    email VARCHAR(190) NOT NULL,
    status ENUM('subscribed', 'unsubscribed') NOT NULL DEFAULT 'subscribed',
    subscribed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    unsubscribed_at TIMESTAMP NULL DEFAULT NULL,
    CONSTRAINT fk_mailing_subscriber_list FOREIGN KEY (list_id)
        REFERENCES cms_mailing_list_lists (id) ON DELETE CASCADE,
    UNIQUE KEY uq_mailing_list_email (list_id, email),
    KEY idx_mailing_subscriber_list_status (list_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

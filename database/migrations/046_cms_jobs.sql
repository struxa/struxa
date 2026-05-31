-- Background job queue (defer heavy work to CLI worker: php bin/cms.php jobs:work)

CREATE TABLE IF NOT EXISTS cms_jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    queue VARCHAR(64) NOT NULL DEFAULT 'default',
    type VARCHAR(128) NOT NULL,
    payload JSON NULL,
    status ENUM('pending', 'running', 'completed', 'failed', 'cancelled') NOT NULL DEFAULT 'pending',
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 3,
    reserved_at DATETIME NULL,
    reserved_by VARCHAR(64) NULL,
    result_summary VARCHAR(512) NULL,
    last_error TEXT NULL,
    dedupe_key VARCHAR(191) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_queue_pending (queue, status, available_at, id),
    KEY idx_dedupe_active (dedupe_key, status),
    KEY idx_type_status (type, status),
    KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

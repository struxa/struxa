-- Aggregated log of requests denied by IP block middleware (throttled writes in app code).

CREATE TABLE IF NOT EXISTS cms_ip_block_hit_buckets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_ip VARCHAR(45) NOT NULL,
  bucket_hour INT UNSIGNED NOT NULL COMMENT 'floor(unix_timestamp/3600)',
  hit_count INT UNSIGNED NOT NULL DEFAULT 0,
  last_path VARCHAR(512) NULL,
  last_user_agent VARCHAR(255) NULL,
  last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ip_block_hit_ip_hour (client_ip, bucket_hour),
  KEY idx_ip_block_hit_last_seen (last_seen_at),
  KEY idx_ip_block_hit_bucket (bucket_hour)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

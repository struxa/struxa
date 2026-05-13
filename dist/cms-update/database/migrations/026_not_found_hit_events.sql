-- Per-request 404 log lines (IP, time, user agent) for Admin → SEO → 404 monitor → Details modal.
CREATE TABLE IF NOT EXISTS cms_not_found_hit_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  log_id INT UNSIGNED NOT NULL,
  client_ip VARCHAR(45) NOT NULL,
  user_agent VARCHAR(512) NULL,
  seen_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_nf_hit_log (log_id),
  KEY idx_nf_hit_seen (seen_at DESC),
  CONSTRAINT fk_cms_nf_hit_log FOREIGN KEY (log_id) REFERENCES cms_not_found_logs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

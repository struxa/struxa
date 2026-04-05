-- AI assistant: usage audit / rate limits, optional persisted chat history.

CREATE TABLE IF NOT EXISTS cms_ai_usage_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  event_type VARCHAR(32) NOT NULL,
  meta_json VARCHAR(512) NULL,
  created_at DATETIME NOT NULL,
  KEY idx_ai_usage_user_time (user_id, created_at),
  KEY idx_ai_usage_user_type_time (user_id, event_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_ai_chat_messages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  role VARCHAR(20) NOT NULL,
  content MEDIUMTEXT NOT NULL,
  created_at DATETIME NOT NULL,
  KEY idx_ai_chat_user_id (user_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO cms_settings (setting_key, setting_value, autoload) VALUES
('ai_rate_chat_per_hour', '60', 1),
('ai_rate_draft_per_day', '40', 1),
('ai_chat_persist', '0', 1),
('ai_chat_retention_days', '30', 1)
ON DUPLICATE KEY UPDATE
  autoload = VALUES(autoload);

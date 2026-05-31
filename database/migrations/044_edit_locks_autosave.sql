-- Collaborative editing: heartbeat locks + per-user server autosaves.

CREATE TABLE IF NOT EXISTS cms_edit_locks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  subject_type VARCHAR(32) NOT NULL,
  subject_id INT UNSIGNED NOT NULL,
  lock_token CHAR(36) NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  heartbeat_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cms_edit_locks_subject (subject_type, subject_id),
  KEY idx_cms_edit_locks_heartbeat (heartbeat_at),
  CONSTRAINT fk_cms_edit_locks_user FOREIGN KEY (user_id) REFERENCES cms_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_autosaves (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  subject_type VARCHAR(32) NOT NULL,
  subject_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  payload_json MEDIUMTEXT NOT NULL,
  updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  UNIQUE KEY uq_cms_autosaves_subject_user (subject_type, subject_id, user_id),
  KEY idx_cms_autosaves_updated (updated_at),
  CONSTRAINT fk_cms_autosaves_user FOREIGN KEY (user_id) REFERENCES cms_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

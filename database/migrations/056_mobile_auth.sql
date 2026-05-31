-- Mobile app refresh tokens (hashed at rest; paired with short-lived JWT access tokens).

CREATE TABLE IF NOT EXISTS cms_mobile_refresh_tokens (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  phpauth_user_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  revoked_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mobile_refresh_hash (token_hash),
  KEY idx_mobile_refresh_user (phpauth_user_id, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

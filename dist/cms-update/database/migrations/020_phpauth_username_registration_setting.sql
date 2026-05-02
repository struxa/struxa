-- Public login accounts: optional unique username; CMS toggle for registration form.

ALTER TABLE phpauth_users
  ADD COLUMN username VARCHAR(64) NULL DEFAULT NULL AFTER email;

CREATE UNIQUE INDEX idx_phpauth_users_username ON phpauth_users (username);

INSERT INTO cms_settings (setting_key, setting_value, autoload) VALUES ('registration_collect_username', '0', 1)
ON DUPLICATE KEY UPDATE
  setting_value = VALUES(setting_value),
  autoload = VALUES(autoload);

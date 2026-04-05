-- TOTP (authenticator app) for CMS users — optional per user after enrollment in admin.
ALTER TABLE cms_users
  ADD COLUMN totp_secret VARCHAR(128) NULL DEFAULT NULL AFTER is_active,
  ADD COLUMN totp_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER totp_secret;

-- Track whether a PHPAuth session was created with "Remember me" so the
-- auto-renew path in AppAuth::checkSession() can preserve the long lifetime
-- instead of downgrading to the short cookie_forget window.

ALTER TABLE phpauth_sessions
  ADD COLUMN remember TINYINT(1) NOT NULL DEFAULT 0 AFTER expiredate;

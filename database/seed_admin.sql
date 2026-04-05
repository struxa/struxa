-- Default administrator (PHPAuth: phpauth_users).
-- Email:    admin@example.com
-- Password: ChangeMe!Admin3439   (change immediately after first login)
--
-- Note: admin@localhost fails PHP FILTER_VALIDATE_EMAIL — use a normal domain.
--
-- Fresh Docker DB: loaded after schema if mounted as 02-seed_admin.sql.
-- Existing DB:
--   docker compose exec -T mysql mysql -ustudio -pstudio studio < database/seed_admin.sql

DELETE FROM phpauth_sessions WHERE uid IN (SELECT id FROM phpauth_users WHERE email IN ('admin@localhost', 'admin@example.com'));
DELETE FROM phpauth_users WHERE email IN ('admin@localhost', 'admin@example.com');

INSERT INTO phpauth_users (email, password, isactive)
VALUES (
  'admin@example.com',
  '$2y$10$/CFDKIxpzHc5kQtifkK1FOgHaVv9AV3Qb8c9/xnhwslzfISR8VRYy',
  1
);

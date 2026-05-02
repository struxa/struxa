-- CMS core: settings store + users linked to PHPAuth (phpauth_users.id).

CREATE TABLE IF NOT EXISTS cms_settings (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  setting_key VARCHAR(191) NOT NULL UNIQUE,
  setting_value MEDIUMTEXT NULL,
  autoload TINYINT(1) NOT NULL DEFAULT 1,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  phpauth_user_id INT NOT NULL UNIQUE,
  email VARCHAR(191) NOT NULL,
  display_name VARCHAR(160) NOT NULL DEFAULT '',
  role ENUM('admin', 'editor', 'author', 'subscriber') NOT NULL DEFAULT 'subscriber',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_cms_users_email (email),
  KEY idx_cms_users_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

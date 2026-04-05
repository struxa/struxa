-- Stage 3: extended site settings (seed) + navigation menus.

INSERT INTO cms_settings (setting_key, setting_value, autoload) VALUES
('site_tagline', 'Software, web & mobile — built with craft.', 1),
('logo_path', '', 1),
('favicon_path', '/favicon.svg', 1),
('default_meta_title', 'Your Studio — Software, Web & Mobile', 1),
('default_meta_description', 'Premium software, web applications, and mobile apps — built with modern tooling and craft.', 1),
('footer_text', '© Your Studio. All rights reserved.', 1),
('contact_email', 'hello@example.com', 1),
('social_facebook', '', 1),
('social_twitter', '', 1),
('social_instagram', '', 1)
ON DUPLICATE KEY UPDATE
  setting_value = VALUES(setting_value),
  autoload = VALUES(autoload);

CREATE TABLE IF NOT EXISTS cms_menus (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  location ENUM('header', 'footer') NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cms_menus_location (location)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_menu_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  menu_id INT UNSIGNED NOT NULL,
  label VARCHAR(191) NOT NULL,
  url VARCHAR(2000) NOT NULL DEFAULT '',
  page_id INT UNSIGNED NULL,
  sort_order INT NOT NULL DEFAULT 0,
  target VARCHAR(16) NOT NULL DEFAULT '_self',
  css_class VARCHAR(191) NOT NULL DEFAULT '',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_cms_menu_items_menu (menu_id),
  KEY idx_cms_menu_items_sort (menu_id, sort_order),
  CONSTRAINT fk_cms_menu_items_menu FOREIGN KEY (menu_id) REFERENCES cms_menus (id) ON DELETE CASCADE,
  CONSTRAINT fk_cms_menu_items_page FOREIGN KEY (page_id) REFERENCES cms_pages (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO cms_menus (name, location) VALUES
('Primary header', 'header'),
('Footer links', 'footer')
ON DUPLICATE KEY UPDATE
  name = VALUES(name);

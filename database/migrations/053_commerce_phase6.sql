-- Phase 6: shipping zones, country tax rates, admin ops settings.

CREATE TABLE IF NOT EXISTS cms_commerce_shipping_zones (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  label VARCHAR(120) NOT NULL,
  price_cents INT UNSIGNED NOT NULL DEFAULT 0,
  free_shipping_min_cents INT UNSIGNED NOT NULL DEFAULT 0,
  countries_json JSON NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_commerce_shipping_zones_active (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_commerce_tax_rates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  country_code CHAR(2) NOT NULL,
  label VARCHAR(120) NOT NULL DEFAULT '',
  rate_bps INT UNSIGNED NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_commerce_tax_country (country_code),
  KEY idx_commerce_tax_active (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO cms_settings (setting_key, setting_value, autoload) VALUES
('commerce_tax_mode', 'flat', 1),
('commerce_use_shipping_zones', '0', 1),
('commerce_low_stock_threshold', '5', 1)
ON DUPLICATE KEY UPDATE setting_key = setting_key;

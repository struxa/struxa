-- Commerce phase 3: coupons, tax, shipping, order totals.

ALTER TABLE cms_commerce_orders
  ADD COLUMN discount_cents INT UNSIGNED NOT NULL DEFAULT 0 AFTER subtotal_cents,
  ADD COLUMN tax_cents INT UNSIGNED NOT NULL DEFAULT 0 AFTER discount_cents,
  ADD COLUMN shipping_cents INT UNSIGNED NOT NULL DEFAULT 0 AFTER tax_cents,
  ADD COLUMN coupon_code VARCHAR(64) NULL AFTER shipping_cents,
  ADD COLUMN shipping_label VARCHAR(128) NULL AFTER coupon_code,
  ADD KEY idx_commerce_orders_customer_email (customer_email, created_at DESC);

CREATE TABLE IF NOT EXISTS cms_commerce_coupons (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(64) NOT NULL,
  discount_type ENUM('percent', 'fixed') NOT NULL DEFAULT 'fixed',
  amount INT UNSIGNED NOT NULL DEFAULT 0,
  min_subtotal_cents INT UNSIGNED NOT NULL DEFAULT 0,
  max_uses INT UNSIGNED NULL,
  uses_count INT UNSIGNED NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  expires_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_commerce_coupon_code (code),
  KEY idx_commerce_coupon_active (active, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO cms_settings (setting_key, setting_value, is_sensitive) VALUES
('commerce_tax_enabled', '0', 0),
('commerce_tax_rate_bps', '2000', 0),
('commerce_shipping_enabled', '0', 0),
('commerce_shipping_flat_cents', '499', 0),
('commerce_free_shipping_min_cents', '5000', 0),
('commerce_shipping_label', 'Standard shipping', 0)
ON DUPLICATE KEY UPDATE setting_key = setting_key;

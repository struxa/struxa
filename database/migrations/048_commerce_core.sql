-- Core commerce: orders for purchasable content-type entries (Stripe Checkout).

CREATE TABLE IF NOT EXISTS cms_commerce_orders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_number VARCHAR(32) NOT NULL,
  status ENUM('pending', 'paid', 'failed', 'cancelled', 'refunded') NOT NULL DEFAULT 'pending',
  currency CHAR(3) NOT NULL DEFAULT 'gbp',
  subtotal_cents INT UNSIGNED NOT NULL DEFAULT 0,
  total_cents INT UNSIGNED NOT NULL DEFAULT 0,
  customer_email VARCHAR(255) NULL,
  stripe_checkout_session_id VARCHAR(255) NULL,
  stripe_payment_intent_id VARCHAR(255) NULL,
  metadata_json MEDIUMTEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  paid_at TIMESTAMP NULL,
  UNIQUE KEY uq_commerce_order_number (order_number),
  KEY idx_commerce_orders_status (status, created_at DESC),
  KEY idx_commerce_stripe_session (stripe_checkout_session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_commerce_order_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  content_entry_id INT UNSIGNED NOT NULL,
  content_type_id INT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL,
  unit_price_cents INT UNSIGNED NOT NULL,
  quantity INT UNSIGNED NOT NULL DEFAULT 1,
  line_total_cents INT UNSIGNED NOT NULL,
  metadata_json MEDIUMTEXT NULL,
  CONSTRAINT fk_commerce_item_order FOREIGN KEY (order_id) REFERENCES cms_commerce_orders (id) ON DELETE CASCADE,
  KEY idx_commerce_item_entry (content_entry_id),
  KEY idx_commerce_item_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO cms_permissions (name, slug, description) VALUES
('Manage commerce', 'manage_commerce', 'Commerce settings, orders, and Stripe configuration.')
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description);

INSERT IGNORE INTO cms_permission_role (permission_id, role_id)
SELECT p.id, r.id FROM cms_permissions p
CROSS JOIN cms_roles r
WHERE p.slug = 'manage_commerce' AND r.slug IN ('super_admin', 'admin');

INSERT INTO cms_settings (setting_key, setting_value, is_sensitive) VALUES
('commerce_enabled', '0', 0),
('commerce_product_type_slug', 'product', 0),
('commerce_currency', 'gbp', 0)
ON DUPLICATE KEY UPDATE setting_key = setting_key;

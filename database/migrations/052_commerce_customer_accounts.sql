-- Commerce phase 5: link orders to logged-in PHPAuth accounts + core shop route support.

ALTER TABLE cms_commerce_orders
  ADD COLUMN customer_user_id INT UNSIGNED NULL AFTER customer_email,
  ADD KEY idx_commerce_orders_customer_user (customer_user_id, created_at DESC);

INSERT INTO cms_settings (setting_key, setting_value, is_sensitive) VALUES
('commerce_shop_title', 'Shop', 0),
('commerce_shop_description', '', 0)
ON DUPLICATE KEY UPDATE setting_key = setting_key;

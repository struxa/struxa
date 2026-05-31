-- Commerce phase 4: shipping address on orders.

ALTER TABLE cms_commerce_orders
  ADD COLUMN shipping_address_json MEDIUMTEXT NULL AFTER shipping_label;

INSERT INTO cms_settings (setting_key, setting_value, is_sensitive) VALUES
('commerce_shipping_countries', 'GB', 0)
ON DUPLICATE KEY UPDATE setting_key = setting_key;

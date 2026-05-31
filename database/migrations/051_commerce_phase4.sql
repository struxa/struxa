-- Commerce phase 4: shipping address on orders.

ALTER TABLE cms_commerce_orders
  ADD COLUMN shipping_address_json MEDIUMTEXT NULL AFTER shipping_label;

INSERT INTO cms_settings (setting_key, setting_value, autoload) VALUES
('commerce_shipping_countries', 'GB', 1)
ON DUPLICATE KEY UPDATE setting_key = setting_key;

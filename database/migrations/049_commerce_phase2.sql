-- Commerce phase 2: order emails, refunds, inventory settings.

ALTER TABLE cms_commerce_orders
  ADD COLUMN confirmation_email_sent_at TIMESTAMP NULL AFTER paid_at,
  ADD COLUMN stripe_refund_id VARCHAR(255) NULL AFTER stripe_payment_intent_id;

INSERT INTO cms_settings (setting_key, setting_value, is_sensitive) VALUES
('commerce_notify_email', '', 0),
('commerce_send_order_emails', '1', 0),
('commerce_track_inventory', '1', 0)
ON DUPLICATE KEY UPDATE setting_key = setting_key;

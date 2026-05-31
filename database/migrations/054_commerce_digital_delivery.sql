-- Phase 7: digital product delivery grants (secure post-purchase access).

CREATE TABLE IF NOT EXISTS cms_commerce_digital_grants (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  order_item_id INT UNSIGNED NOT NULL,
  content_entry_id INT UNSIGNED NOT NULL,
  access_token CHAR(64) NOT NULL,
  delivery_type ENUM('file', 'url', 'entry') NOT NULL,
  delivery_payload_json JSON NOT NULL,
  label VARCHAR(255) NOT NULL DEFAULT 'Download',
  revoked_at TIMESTAMP NULL,
  download_count INT UNSIGNED NOT NULL DEFAULT 0,
  last_download_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_commerce_digital_token (access_token),
  KEY idx_commerce_digital_order (order_id),
  CONSTRAINT fk_commerce_digital_order FOREIGN KEY (order_id) REFERENCES cms_commerce_orders (id) ON DELETE CASCADE,
  CONSTRAINT fk_commerce_digital_item FOREIGN KEY (order_item_id) REFERENCES cms_commerce_order_items (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

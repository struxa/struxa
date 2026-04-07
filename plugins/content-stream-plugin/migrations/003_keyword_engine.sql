-- Keyword Engine: monthly blog calendars derived from domain analysis (Content Stream plugin).

CREATE TABLE IF NOT EXISTS cms_plugin_content_stream_keyword_plans (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  plan_month CHAR(7) NOT NULL COMMENT 'YYYY-MM calendar month',
  label VARCHAR(255) NULL,
  domain VARCHAR(255) NULL,
  analysis_json LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_cs_kp_plan_month (plan_month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_plugin_content_stream_keyword_plan_items (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  plan_id INT UNSIGNED NOT NULL,
  day_index TINYINT UNSIGNED NOT NULL COMMENT '1 = first day of month',
  primary_keyword VARCHAR(512) NOT NULL,
  search_intent VARCHAR(32) NULL,
  title VARCHAR(500) NOT NULL,
  outline_json LONGTEXT NULL,
  meta_description VARCHAR(320) NULL,
  opportunity_score DECIMAL(5,2) NULL,
  score_rationale TEXT NULL,
  CONSTRAINT fk_cs_kp_item_plan FOREIGN KEY (plan_id) REFERENCES cms_plugin_content_stream_keyword_plans (id) ON DELETE CASCADE,
  UNIQUE KEY uq_cs_kp_plan_day (plan_id, day_index),
  KEY idx_cs_kp_plan (plan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

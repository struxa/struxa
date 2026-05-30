-- Logical media folders (DB-only; on-disk paths stay date-based under /uploads/).

CREATE TABLE IF NOT EXISTS cms_media_folders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  slug VARCHAR(191) NOT NULL,
  parent_id INT UNSIGNED NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_cms_media_folders_parent_sort (parent_id, sort_order, id),
  KEY idx_cms_media_folders_slug (slug(64)),
  CONSTRAINT fk_cms_media_folders_parent FOREIGN KEY (parent_id) REFERENCES cms_media_folders (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE cms_media
  ADD COLUMN folder_id INT UNSIGNED NULL AFTER uploaded_by,
  ADD KEY idx_cms_media_folder (folder_id, created_at),
  ADD CONSTRAINT fk_cms_media_folder FOREIGN KEY (folder_id) REFERENCES cms_media_folders (id) ON DELETE SET NULL;

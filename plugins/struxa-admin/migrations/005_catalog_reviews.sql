-- Unified catalog reviews (one per member per package: rating + text).

CREATE TABLE IF NOT EXISTS cms_struxa_catalog_reviews (
    kind ENUM('plugin', 'theme') NOT NULL,
    slug VARCHAR(128) NOT NULL,
    cms_user_id INT UNSIGNED NOT NULL,
    rating TINYINT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (kind, slug, cms_user_id),
    KEY idx_catalog_reviews_package (kind, slug, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO cms_struxa_catalog_reviews (kind, slug, cms_user_id, rating, body, created_at, updated_at)
SELECT r.kind, r.slug, r.cms_user_id, r.rating,
       COALESCE((
           SELECT c.body
           FROM cms_struxa_catalog_comments c
           WHERE c.kind = r.kind AND c.slug = r.slug AND c.cms_user_id = r.cms_user_id AND c.status = 'visible'
           ORDER BY c.created_at DESC
           LIMIT 1
       ), ''),
       r.created_at, r.updated_at
FROM cms_struxa_catalog_ratings r
ON DUPLICATE KEY UPDATE
    rating = VALUES(rating),
    body = IF(VALUES(body) <> '', VALUES(body), body),
    updated_at = VALUES(updated_at);

INSERT INTO cms_struxa_catalog_reviews (kind, slug, cms_user_id, rating, body, created_at, updated_at)
SELECT c.kind, c.slug, c.cms_user_id, 5, c.body, c.created_at, c.created_at
FROM cms_struxa_catalog_comments c
WHERE c.status = 'visible'
  AND TRIM(c.body) <> ''
  AND NOT EXISTS (
      SELECT 1 FROM cms_struxa_catalog_reviews rv
      WHERE rv.kind = c.kind AND rv.slug = c.slug AND rv.cms_user_id = c.cms_user_id
  );

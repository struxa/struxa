-- Link comments to PHPAuth accounts, and per-user likes (signed-in users only for new posts).

ALTER TABLE cms_comments
  ADD COLUMN user_id INT NULL DEFAULT NULL AFTER thread_key,
  ADD KEY idx_cms_comments_user_id (user_id),
  ADD CONSTRAINT fk_cms_comments_phpauth_user
    FOREIGN KEY (user_id) REFERENCES phpauth_users(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS cms_comment_likes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  comment_id BIGINT UNSIGNED NOT NULL,
  user_id INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_comment_likes_pair (comment_id, user_id),
  KEY idx_comment_likes_comment (comment_id),
  KEY idx_comment_likes_user (user_id),
  CONSTRAINT fk_comment_likes_comment FOREIGN KEY (comment_id) REFERENCES cms_comments(id) ON DELETE CASCADE,
  CONSTRAINT fk_comment_likes_user FOREIGN KEY (user_id) REFERENCES phpauth_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

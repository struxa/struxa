-- Guides Importer — set up the "Guides" CMS content type.
--
-- Why a content type and not a plugin-owned table?
-- Each row in the TSV maps cleanly onto cms_content_entries (title, slug,
-- SEO title/description, featured image, status) plus a couple of richtext /
-- text fields. Letting the CMS own the rows means we get:
--   * the public /guides + /guides/{slug} routes for free,
--   * SEO + sitemap + canonical tags via the existing pipeline,
--   * admin browsing/editing under Content → Guides,
--   * Open Graph / Twitter cards via base.twig blocks.
-- The plugin keeps a thin `guides_imports` link table so the importer is
-- idempotent (re-runs skip rows already mapped by their legacy_id).

-- 1) Create the "Guides" content type if it doesn't exist.
INSERT INTO cms_content_types
    (name, slug, icon, description, has_public_route, supports_seo, supports_featured_image)
SELECT 'Guides', 'guides', 'book-open-reader',
       'Long-form travel and loyalty guides imported from the WordPress AJAX scraper export. Each entry is a standalone article with SEO metadata and a richtext body.',
       1, 1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM cms_content_types WHERE slug = 'guides');

-- 2) Rich-text article body (the meat of the page).
INSERT INTO cms_content_fields
    (content_type_id, label, field_key, field_type, placeholder, help_text, is_required, sort_order)
SELECT t.id, 'Article body', 'body', 'richtext',
       NULL,
       'Main HTML article. Imported as-is from the TSV export; safe to hand-edit.',
       0, 10
FROM cms_content_types t
WHERE t.slug = 'guides'
  AND NOT EXISTS (
    SELECT 1 FROM cms_content_fields f
    WHERE f.content_type_id = t.id AND f.field_key = 'body'
  );

-- 3) Short summary — used as the public excerpt + fallback meta description.
INSERT INTO cms_content_fields
    (content_type_id, label, field_key, field_type, placeholder, help_text, is_required, sort_order)
SELECT t.id, 'Short summary', 'summary', 'textarea',
       NULL,
       'One- or two-sentence excerpt shown on the /guides index and used as a meta description fallback when the SEO description is blank.',
       0, 5
FROM cms_content_types t
WHERE t.slug = 'guides'
  AND NOT EXISTS (
    SELECT 1 FROM cms_content_fields f
    WHERE f.content_type_id = t.id AND f.field_key = 'summary'
  );

-- 4) Word count (text rather than number so the field renders gracefully
--    when the source has it blank).
INSERT INTO cms_content_fields
    (content_type_id, label, field_key, field_type, placeholder, help_text, is_required, sort_order)
SELECT t.id, 'Generated word count', 'word_count', 'text',
       NULL,
       'Word count of the generated body, as reported by the source pipeline. Editorial reference only.',
       0, 40
FROM cms_content_types t
WHERE t.slug = 'guides'
  AND NOT EXISTS (
    SELECT 1 FROM cms_content_fields f
    WHERE f.content_type_id = t.id AND f.field_key = 'word_count'
  );

-- 5) Idempotency map: legacy_id (the TSV row's id column) → content entry id.
--    Lets us re-run the importer safely: rows with a known legacy_id are
--    skipped (or updated if you pass --refresh). entry_id is nullable so we
--    can mark a row as "seen but failed to materialise" if we ever need to.
CREATE TABLE IF NOT EXISTS guides_imports (
    id            INT UNSIGNED       NOT NULL AUTO_INCREMENT,
    legacy_id     INT UNSIGNED       NOT NULL,
    entry_id      INT UNSIGNED       NULL,
    source_hash   CHAR(40)           NULL,
    imported_at   TIMESTAMP          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_legacy_id (legacy_id),
    KEY idx_entry_id (entry_id),
    CONSTRAINT fk_guides_imports_entry FOREIGN KEY (entry_id)
        REFERENCES cms_content_entries (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional interlinks: guides → other entries (typically destinations).
-- Values are a JSON array of entry IDs, e.g. [12, 34]. Filled by editors or by
-- `scripts/link-guides-destinations-entry-refs.php`.

INSERT INTO cms_content_fields
    (content_type_id, label, field_key, field_type, placeholder, help_text, is_required, options_json, sort_order)
SELECT
    t.id,
    'Related destinations',
    'related_destinations',
    'entry_refs',
    NULL,
    'Link to destination reviews or other routable entries. Paste JSON array of entry IDs from admin URLs, e.g. [42, 91].',
    0,
    '{"max_refs": 12, "require_public_targets": true}',
    15
FROM cms_content_types t
WHERE t.slug = 'guides'
  AND NOT EXISTS (
      SELECT 1 FROM cms_content_fields f
      WHERE f.content_type_id = t.id AND f.field_key = 'related_destinations'
  );

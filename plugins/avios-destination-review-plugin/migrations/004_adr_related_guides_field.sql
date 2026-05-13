-- Optional interlinks: destination review → guides that discuss the same airport / region.
-- Values are a JSON array of guide entry IDs. Filled by editors or by
-- `plugins/guides-plugin/scripts/link-guides-destinations-entry-refs.php`.

INSERT INTO cms_content_fields
    (content_type_id, label, field_key, field_type, placeholder, help_text, is_required, options_json, sort_order)
SELECT
    t.id,
    'Related guides',
    'related_guides',
    'entry_refs',
    NULL,
    'Link to Avios guides (or other routable entries). Paste JSON array of entry IDs, e.g. [101, 102].',
    0,
    '{"target_content_type_id": 0, "max_refs": 12, "require_public_targets": true}',
    8
FROM cms_content_types t
WHERE t.slug = 'destinations'
  AND NOT EXISTS (
      SELECT 1 FROM cms_content_fields f
      WHERE f.content_type_id = t.id AND f.field_key = 'related_guides'
  );

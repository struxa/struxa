-- Drop editorial-only source metadata from Guides (not shown on the public site).
-- cms_content_entry_values rows for these fields are removed via ON DELETE CASCADE
-- on fk_cms_entry_values_field.

DELETE FROM cms_content_fields
WHERE field_key IN ('source_title', 'source_url')
  AND content_type_id = (SELECT id FROM cms_content_types WHERE slug = 'guides' LIMIT 1);

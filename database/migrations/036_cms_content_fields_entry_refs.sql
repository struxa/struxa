-- Extend cms_content_fields.field_type so Struxa "entry_refs" (content relationships) can be stored.
-- Safe to re-run on MySQL 8: MODIFY replaces the ENUM list with the full canonical set including entry_refs.

ALTER TABLE cms_content_fields
  MODIFY COLUMN field_type ENUM(
    'text',
    'textarea',
    'richtext',
    'number',
    'boolean',
    'select',
    'image',
    'date',
    'url',
    'entry_refs'
  ) NOT NULL;

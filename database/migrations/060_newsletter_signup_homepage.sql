-- Newsletter signup form + Vision homepage section (mailing list).

INSERT INTO cms_forms (
    name, slug, description, status, form_type,
    submit_label, next_label, prev_label,
    confirmation_type, confirmation_message, confirmation_redirect_url,
    honeypot_enabled, notify_enabled, notify_emails, notify_subject, settings_json
)
SELECT
    'Newsletter signup',
    'newsletter-signup',
    'Homepage mailing list — email capture for product updates.',
    'published',
    'standard',
    'Subscribe',
    'Next',
    'Previous',
    'message',
    'Thanks — you''re on the list. Watch your inbox for Struxa updates.',
    NULL,
    1,
    0,
    NULL,
    'New newsletter signup',
    NULL
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM cms_forms WHERE slug = 'newsletter-signup' LIMIT 1);

INSERT INTO cms_form_fields (form_id, field_key, field_type, label, placeholder, help_text, required, options_json, sort_order, page_number, settings_json, conditional_json)
SELECT f.id, 'email', 'email', 'Email address', 'you@example.com', NULL, 1, NULL, 10, 1, NULL, NULL
FROM cms_forms f
WHERE f.slug = 'newsletter-signup'
  AND NOT EXISTS (
    SELECT 1 FROM cms_form_fields ff WHERE ff.form_id = f.id AND ff.field_key = 'email' LIMIT 1
  );

INSERT INTO cms_form_fields (form_id, field_key, field_type, label, placeholder, help_text, required, options_json, sort_order, page_number, settings_json, conditional_json)
SELECT f.id, '_hp_url', 'honeypot', 'Leave blank', NULL, NULL, 0, NULL, 9999, 1, NULL, NULL
FROM cms_forms f
WHERE f.slug = 'newsletter-signup'
  AND NOT EXISTS (
    SELECT 1 FROM cms_form_fields ff WHERE ff.form_id = f.id AND ff.field_type = 'honeypot' LIMIT 1
  );

INSERT INTO cms_page_sections (page_id, sort_order, section_key, data_json, options_json)
SELECT
    p.id,
    2,
    'vision_newsletter',
    '{"form_slug":"newsletter-signup","title":"Join our mailing list","lead":"Product updates, release notes, and Struxa tips — no spam, unsubscribe anytime.","footnote":"We use Struxa Forms to store signups. Export entries anytime from Admin → Forms.","show_form_link":"1"}',
    '{"padding":"comfortable","background":"default"}'
FROM cms_pages p
WHERE p.slug = 'home'
  AND NOT EXISTS (
    SELECT 1 FROM cms_page_sections ps
    WHERE ps.page_id = p.id AND ps.section_key = 'vision_newsletter'
    LIMIT 1
  );

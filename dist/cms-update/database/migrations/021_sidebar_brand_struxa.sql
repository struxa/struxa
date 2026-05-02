-- Align admin sidebar branding with Struxa defaults for installs that still have legacy values.
-- templates/admin/base.twig defaults only apply when settings are absent; DB rows always win.

UPDATE cms_settings
SET setting_value = 'Struxa'
WHERE setting_key = 'cms_panel_title'
  AND LOWER(TRIM(setting_value)) = 'pulse';

UPDATE cms_settings
SET setting_value = 'struxapoint.com'
WHERE setting_key = 'site_name'
  AND LOWER(TRIM(setting_value)) IN ('struxa', 'struxa.');

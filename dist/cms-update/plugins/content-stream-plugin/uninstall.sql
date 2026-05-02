-- Executed when the plugin folder is removed via Admin → Extensions → Remove plugin.
-- Order: child tables first.

DROP TABLE IF EXISTS cms_plugin_content_stream_keyword_plan_items;
DROP TABLE IF EXISTS cms_plugin_content_stream_keyword_plans;
DROP TABLE IF EXISTS cms_plugin_content_stream_settings;

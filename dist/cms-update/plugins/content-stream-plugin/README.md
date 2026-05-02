# Content Stream plugin

1. Run `plugins/content-stream-plugin/migrations/001_content_stream_settings.sql` against your CMS database.
2. Admin → **Extensions** → activate **Content Stream**.
3. Admin → **Content Stream** (sidebar under Extensions): add an OpenAI API key (requires **Manage site settings**).
4. **Domain tool** (staff only):
   - **Admin:** Extensions → **Content Stream · domain tool**, or **Content Stream · API settings** → “Open domain tool”.
   - **URL:** `/content-stream` (redirects to login if needed).  
   This is **not** a row in **Pages**; it is a plugin route. The slug `content-stream` is reserved so it cannot collide with a content type.

No extra Composer packages (uses PHP `file_get_contents` to the OpenAI REST API).

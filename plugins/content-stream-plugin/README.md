# Content Stream plugin

1. Run `plugins/content-stream-plugin/migrations/001_content_stream_settings.sql` against your CMS database.
2. Admin → **Extensions** → activate **Content Stream**.
3. Admin → **Extensions** → expand **Content Stream** → **API settings**: add an OpenAI API key (requires **Manage site settings**).
4. **Domain tool** (staff only):
   - **Admin:** Extensions → **Content Stream** → **Domain tool**, or **API settings** → “Open domain tool”.
   - **URL:** `/content-stream` (redirects to login if needed).  
   This is **not** a row in **Pages**; it is a plugin route. The slug `content-stream` is reserved so it cannot collide with a content type.

No extra Composer packages (uses PHP `file_get_contents` to the OpenAI REST API).

Struxa CMS — safe FTP update package
====================================

This ZIP is meant to MERGE into an existing install. It does NOT include:

  • .env              (keep your server copy — DB, keys, site URL)
  • storage/          (cache, locks, theme state — keep on server)
  • public/uploads/   (your media library files — not in this ZIP)

It DOES include application code, the default theme, templates, migrations,
composer.lock, and vendor/ (production dependencies) so shared hosting works
without running Composer on the server.

It does NOT include plugins/ — install extensions from Admin → Plugins → Browse
catalog (or copy plugin folders manually). Automatic CMS updates also skip
plugins/ on your server so an update cannot overwrite or add bundled plugins.

------------------------------------------------------------
Steps (FTP)
------------------------------------------------------------

1. Back up first: download copies of .env, storage/, and public/uploads/.

2. Unzip locally. Upload the folder "cms-update" contents into your site root
   (same level as public/, src/, bootstrap/) — merge/replace files when asked.

   Do NOT delete your live .env, storage/, or public/uploads/ on the server.

3. If your host provides SSH or a cron PHP runner, apply database migrations:

     php bin/migrate.php

   Or from project root:

     composer migrate

   New SQL files live under database/migrations/. The AI tools screen needs
   at least migration 024 (OpenAI settings) and 025 (usage + optional chat
   persistence tables); without 025 the app now degrades gracefully, but you
   should still run migrate so rate limits and usage stats work.

4. Clear CMS cache if the admin has a cache tool, or remove files under
   storage/cache/ on the server (not the whole storage folder).

5. If anything looks wrong after upload, restore from backup — especially .env.

------------------------------------------------------------
Optional: Composer on the server
------------------------------------------------------------

If you prefer not to upload vendor/, delete vendor/ from this package and run
`composer install --no-dev --optimize-autoloader` on the server instead.

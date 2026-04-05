# Project structure

## Top-level layout

- **`public/`** — Web root (`index.php` bootstrap, static assets such as `css/admin.css`).
- **`src/`** — Application code (PSR-4 `App\` namespace).
- **`templates/`** — Core Twig templates (admin shell, auth pages).
- **`themes/`** — Installable front-end themes (`themes/{slug}/`).
- **`plugins/`** — Installable plugins (`plugins/{slug}/`).
- **`database/migrations/`** — Ordered `.sql` migrations.
- **`storage/`** — Writable data (e.g. blueprints under `storage/blueprints/`).
- **`routes/`** — Route group closures included from `public/index.php`.
- **`bin/`** — CLI scripts (`migrate.php`, `cms.php`).

## Request lifecycle

1. `public/index.php` loads Composer autoload, optional `.env`, connects to MySQL, boots `Settings`, builds Slim `App`.
2. Twig is configured with theme view paths, CMS extensions, and `TwigCmsGlobals` middleware (settings, menus, active theme, plugin admin nav).
3. Core routes register first; plugin public routes register before catch-all content routes; plugin admin routes register before variable public routes.
4. Active plugins are loaded from `cms_plugins` (filesystem manifest + optional `PluginServiceProvider`).

## Notable `App\` areas

- **`Access/`** — Permissions, roles, middleware.
- **`Content/`** — Content types, entries, fields, revisions.
- **`Theme/`** — Theme discovery, manifest parsing, asset HTTP handler.
- **`Plugin/`** — Plugin scanner, manager, manifest parser.
- **`Blueprint/`** — Blueprint validation and import/export helpers.
- **`Manifest/`** — Shared manifest field parsing (e.g. tags, URLs).

Keep new features close to existing modules rather than introducing parallel “frameworks” inside the repo.

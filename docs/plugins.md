# Plugins

## Location

Each plugin is **`plugins/{slug}/`** where `{slug}` matches `^[a-z0-9]+(?:-[a-z0-9]+)*$`.

## Required files

- **`plugin.json`** — Manifest: `name`, `slug`, `version`, `author`, optional `main_class`, `autoload.psr4`, marketplace fields, CMS/PHP requirements.
- **`src/`** — PHP source (PSR-4 as declared in `plugin.json`).

## Optional

- **`routes/public.php`** — Receives `(App $app, PluginBootContext $ctx)`; register public routes.
- **`routes/admin.php`** — Same signature; register routes under `/admin/...` (register **before** core registers variable public routes).
- **`views/`** — Twig namespace `@plugin_{slug_with_underscores}` (hyphens become underscores).
- **`migrations/`** — Plugin-specific SQL if you document and run them manually or via your own process (core migrator targets `database/migrations/` only).

## Lifecycle

1. **Discovery** — `PluginScanner` reads valid `plugin.json` files.
2. **Activation** — Rows in **`cms_plugins`** mark which slugs are active. Pending `migrations/*.sql` files are applied and recorded in **`cms_plugin_migrations`**.
3. **Boot** — For each active plugin, autoload is registered, Twig paths added, routes loaded, then `main_class` (if present) is instantiated; if it implements `PluginServiceProviderInterface`, `boot()` runs (nav items, event listeners, **filters**, reserved URL segments).
4. **Remove (delete from disk)** — If the plugin root contains **`uninstall.sql`**, it is executed (typically `DROP TABLE` for that plugin’s tables), then **`cms_plugin_migrations`** rows for that slug are deleted so a future reinstall can run migrations again.

## Reserved URL segments (content type slugs)

Struxa blocks certain first-path segments so they cannot be used as **content type** slugs (which would collide with `/{typeSlug}` and `/{typeSlug}/{entrySlug}` routes).

| Layer | Responsibility |
| --- | --- |
| **Struxa CMS core** | `registerPluginReservedSlugs()` API, core `RESERVED` list (admin, api, login, search, theme-assets, media, robots.txt, …), docs, tests |
| **Your site plugin** | Segments **your** plugin owns, e.g. `my-catalog` or (on a downstream project) `casino-review` — registered in **that plugin’s** `boot()`, never in core |

**Principle:** Struxa provides `registerPluginReservedSlugs()`; each plugin registers the path segments it owns. Core never hardcodes application-specific routes.

If your plugin adds public routes, implement `PluginServiceProviderInterface` and reserve matching segments in `boot()` (required when you use `routes/public.php`):

```php
// In *your* plugin's boot() — illustrative only; do not add these to CMS core:
public function boot(PluginBootContext $context): void
{
    $context->registerPluginReservedSlugs([
        'my-catalog',   // GET /my-catalog
        'my-reviews',   // GET /my-reviews/{slug}
    ]);
}
```

`registerReservedContentSlugs()` is an alias with the same behavior. Invalid or empty segments are ignored. Segments must match `^[a-z0-9]+(?:-[a-z0-9]+)*$` (lowercase).

**Bundled example:** `content-stream-plugin` registers `content-stream` for its staff tool at `/content-stream` (see `ContentStreamServiceProvider`).

## Filter pipeline (`apply_filters`)

Plugins can **transform** core output at runtime (not just react to events). In `boot()`:

```php
use App\Filter\FilterHook;

public function boot(PluginBootContext $context): void
{
    $context->addFilter(FilterHook::SEO_META, function (array $meta, array $ctx): array {
        if (($ctx['subject'] ?? '') === 'page') {
            $meta['og_title'] = '[Brand] ' . ($meta['og_title'] ?? '');
        }

        return $meta;
    }, 10);

    $context->addFilter(FilterHook::MENU_ITEMS, function (array $items, array $ctx): array {
        $items[] = ['label' => 'Status', 'href' => '/status', 'target' => '', 'css_class' => ''];

        return $items;
    });
}
```

| Hook constant | Value | Transforms |
| --- | --- | --- |
| `FilterHook::SEO_META` | `seo.meta` | Resolved meta array (see `SeoMetaFilter::toArray()`) |
| `FilterHook::HTML_SANITIZE` | `html.sanitize` | Sanitized HTML string after HTMLPurifier |
| `FilterHook::MENU_ITEMS` | `menu.items` | Public menu item list for a location |
| `FilterHook::API_ENTRY_RESPONSE` | `api.entry.response` | REST entry detail JSON payload |
| `FilterHook::API_PAGE_RESPONSE` | `api.page.response` | REST page detail JSON payload |
| `FilterHook::API_ENTRY_REQUEST` | `api.entry.request` | Inbound REST entry write body before validation |

Lower **priority** runs first (default `10`). Callbacks receive `(mixed $value, array $context)` and must **return** the next value.

## Browse and install from the catalog

**Extensions → Plugins → Browse catalog** installs packages from the same distribution registry as themes (`https://struxapoint.com/struxa-dist/repo.json` by default). The catalog’s **`plugins`** array lists slug, metadata, and **`download_url`** (HTTPS ZIP). After install, **Activate** the plugin to run migrations and load routes.

Host your own registry: publish the **`struxa-dist/`** folder (see **`struxa-dist/README.md`**) to a separate git repo / CDN and set **`STRUXA_DIST_CATALOG_URL`** on each CMS site. Local dev: copy **`storage/dist-catalog.example.json`** to **`storage/dist-catalog.json`**.

## Example plugins

See **`plugins/hello-plugin`**, **`plugins/seo-helper-plugin`**, **`plugins/analytics-widget-plugin`**, **`plugins/content-stream-plugin`** (OpenAI-backed public form + admin API settings), and **`plugins/mailing-list-plugin`** (multiple lists, email validation, storefront signup) for small, copy-paste-friendly patterns.

**Commerce:** **`plugins/stripe-store-plugin`** adds Stripe Checkout for purchasable content entries (e.g. products) without core code changes. Run its SQL migration, **`composer install` at the project root** (Stripe PHP is required in the root `composer.json`), activate the plugin, add optional content fields `stripe_price_id` / `stripe_amount_cents`, and include `<script src="…/stripe-store/embed.js" defer></script>` on your storefront layout.

## Plugin Composer dependencies

Formal policy (root vs plugin-local, CI, exit codes): **`docs/plugins-dependencies.md`**.

Plugins may ship a **`plugins/{slug}/composer.json`** for third-party libraries. Deploy using **one** of these (mix as needed):

1. **Root metapackage (recommended for shared libs)** — Add the same package to the **repo root** `composer.json` (e.g. `stripe/stripe-php`). A single `composer install` at deploy satisfies core + plugins that rely on the global autoloader. The Stripe store plugin loads Stripe via `class_exists(..., true)` after the app has already bootstrapped root `vendor/autoload.php`.

2. **Per-plugin `vendor/` (isolated tree)** — From `plugins/{slug}/`, run `composer install`. Repeat for each plugin with a `composer.json`. Automate with:
   - **`composer plugin-deps`** — runs `composer install` in every plugin directory that has `composer.json`.
   - **`composer plugin-deps:prod`** — same with `--no-dev` (CI/production).
   - Dry run (list only): **`php bin/plugin-deps.php --dry-run`** or **`composer plugin-deps -- --dry-run`** (Composer forwards args after `--`).

   Optional: add `plugins/*/vendor/` to `.gitignore` and run `composer plugin-deps` in CI after `composer install`.

3. **Committed `vendor/`** — Check plugin `vendor/` into git for maximum reproducibility without Composer on the server (not ideal for security updates, but valid for locked appliances).

**CI sketch:** `composer install --no-dev` at root, then `composer plugin-deps:prod`, then **`composer check:plugin-deps`** (or **`--strict`** to fail on hoist warnings).

Read **`docs/plugins-dependencies.md`** for when dependencies must live in the root `composer.json` vs a plugin tree.

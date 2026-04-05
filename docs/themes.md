# Themes

## Location

Each theme lives in **`themes/{slug}/`**. The directory name must match the `slug` in `theme.json`.

## Required layout

- **`theme.json`** — Manifest (name, slug, version, author, optional `parents`, `settings`, marketplace metadata).
- **`views/`** — Twig templates (layouts, pages, content templates).
- **`assets/`** — Public assets served under the theme asset route (CSS, JS, images).

Optional: **`screenshot`** in `theme.json` pointing to a file inside the theme (e.g. `assets/screenshot.png`).

## Inheritance

The `parents` array lists ancestor theme slugs (furthest parent first). Twig’s loader order is **core `templates/`**, then the **active theme’s `views/`**, then each parent from **nearest to furthest**. The first directory that contains a template wins, so the active theme overrides parents; parents supply fallbacks for templates the child does not ship.

## Settings

`settings` in `theme.json` defines a schema of editable keys (labels, types, defaults). Resolved values are exposed to Twig as `theme_settings`.

## Activation

The active theme slug is stored in **`cms_settings`** (`active_theme`). The admin **Themes** screen writes this value. `ThemeManager` resolves view paths and public asset URLs for the active theme (and its parents).

## Theme catalog (browse & install)

**Themes → Browse catalog** loads a JSON registry and can install themes from HTTPS ZIP URLs into **`themes/{slug}/`**.

- **Default:** if **`STRUXA_THEME_CATALOG_URL`** is not set, the CMS fetches **`https://struxapoint.com/theme-repo/repo.json`** (must return JSON with a **`themes`** array). If that request fails, it falls back to **`storage/theme-catalog.json`** when that file exists.
- **Override:** set **`STRUXA_THEME_CATALOG_URL`** to an `https://` URL that returns the same JSON shape.

Each entry needs **`slug`** and **`download_url`** (https only). Optional: **`name`**, **`version`**, **`description`**, **`author`**. The ZIP may use a single top-level folder (e.g. GitHub’s `repo-main/`); the installer finds a valid `theme.json` with **`views/`** and **`assets/`** and installs as **`themes/{slug}/`**. The **`slug` inside `theme.json`** must match the catalog **`slug`**.

**Download hosts:** Defaults allow common GitHub hosts. Add more with comma-separated **`STRUXA_THEME_DOWNLOAD_HOSTS`** (hostname only).

## Front-end globals

Twig receives `active_theme`, `active_theme_manifest`, `theme_settings`, `settings`, menus, and helpers such as `theme_asset()`.

## Public layout contract

Twig loads **core `templates/` first**, then parent themes, then the active theme. The first filesystem path that contains a template name wins.

- **Core marketing / auth shell** — **`public/root.twig`** (`App\Theme\PublicLayoutContract::PUBLIC_ROOT`). Core pages, plugins, and anything that should use the global header, `/css/styles.css`, and shared menus should extend **`public/root.twig`** or the alias **`base.twig`** (which only extends `public/root.twig`). In Twig you can also use `{% extends public_layout() %}`.

- **Theme storefront shell** — **`layouts/base.twig`** under the active theme only (`PublicLayoutContract::THEME_SHELL`, or `theme_layout()` in Twig). Content templates that should use theme CSS/partials must extend this — **not** `base.twig`, or resolution picks core’s marketing layout first.

**Do not** add **`templates/layouts/base.twig`** in core: it would shadow every theme’s `layouts/base.twig` and break storefront layouts.

**Contributor warning:** The layout split above is paired with a **strict asset boundary** for anything rendered through the theme shell (pages, **all content types**, taxonomies, theme home). Storefront Twig must not depend on core marketing stylesheets. See **[Storefront Asset Boundary](#storefront-asset-boundary)** below.

See also `ThemeViewResolver` docblock and `docs/plugins.md` for plugin views (extend `public/root.twig`).

## Storefront Asset Boundary

This rule applies to **every customer-facing storefront path** resolved under the active theme: static pages, **all content types** (indexes, singles, custom per-type views), taxonomy archives, and any template that extends **`layouts/base.twig`** (or `theme_layout()`).

### Rules

1. **Core marketing shell may use core public assets**  
   Layouts and partials that extend **`templates/public/root.twig`** (or core `base.twig` / `public_layout()`) may load **`public/css/styles.css`**, **`public/js/main.js`**, and other files under **`public/`** as today. Example document shell: **`templates/public/root.twig`**.

2. **Theme storefront must use theme assets only**  
   Customer-facing storefront pages must load CSS, JS, and static images through theme helpers (e.g. **`{{ theme_asset('css/app.css') }}`**, **`{{ theme_asset('js/main.js') }}`**) so URLs resolve under the active theme’s **`assets/`** directory and the **`/theme-assets/…`** route.

3. **Storefront templates must never depend on core marketing CSS**  
   Do not link **`/css/styles.css`** (or other marketing-only bundles) from **`themes/*/views/**`**. Do not assume classes, variables, or components from **`public/css/styles.css`** exist. Storefront markup and styles must remain **portable** when the active theme (or theme version) changes.

### Correct vs incorrect

| Correct (theme storefront) | Incorrect |
| -------------------------- | --------- |
| In **`themes/default/views/layouts/base.twig`**: `<link rel="stylesheet" href="{{ theme_asset('css/app.css') }}" />` | In a theme layout or **`themes/*/views/content/*.twig`**: `<link rel="stylesheet" href="/css/styles.css" />` or relying on marketing-only class names without defining them in the theme |

### Why this matters

- **Portable themes** — Installers can swap themes without dragging in the marketing site’s design system.  
- **No hidden coupling** — Storefront pages do not break when **`styles.css`** is refactored for the landing or admin-adjacent marketing shell.  
- **White-label and sellable themes** — Commercial or client themes ship self-contained assets.  
- **Fewer regressions** — Blog, product, and other content-type archives stay stable independent of core marketing CSS changes.

## Automated layout lint

Run **`composer lint:twig-layouts`** to enforce `PublicLayoutContract` rules (forbidden core `layouts/base.twig`, theme vs core `base.twig`, plugin public vs theme shell, unresolved `extends` parents). Optional: **`--warn-duplicates`** for core/theme path collisions; **`--strict`** to fail on warnings. The same checks run during **`composer test`**.

## Marketing homepage (no duplicate static HTML)

By default **`GET /`** renders **`page/home.twig` in the active theme** (optionally extending parent themes). In **Admin → Site settings → Public site homepage**, you can assign a **published CMS page** instead: that page’s body, visual sections, and SEO are then served at **`/`** (canonical `/`), while **`/p/{slug}`** for that page **301-redirects** to `/`. If the setting is empty or invalid, the theme template is used. Core `templates/` must not define **`page/home.twig`** or **`page/show.twig`** — either file shadows the active theme (you would get the wrong layout/CSS). Queue-specific section fallbacks live under **`themes/default/views/sections/`**, not core, so **Builder Queue** can override them. Repository-root `index.html` is a non-canonical pointer; optional HTML export is `composer render-marketing-preview` → `public/marketing-preview.html`. Details: **`docs/frontend.md`**.

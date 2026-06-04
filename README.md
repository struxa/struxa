# Struxa CMS

**Version:** 1.2.23 (canonical: `composer.json` → `version`)

PHP content management on **Slim 4** and **Twig**: custom content types, storefront themes, admin UI, media library, SEO tools, optional **AI writing assistant**, headless **JSON/GraphQL** API, and **plugins**.

## Requirements

- **PHP** 8.2+ with extensions: `mbstring`, `pdo_mysql`, `json`, `curl`, and **`gd`** (JPEG/PNG/WebP; required for media compression and responsive thumbnails)
- **MySQL** 8+ (or compatible)
- **Composer** 2.x

## Quick start (local)

```bash
git clone https://github.com/struxa/struxa.git
cd struxa
cp .env.example .env
# Edit .env: set DB_*, PHPAUTH_SITE_KEY, PHPAUTH_SITE_URL, etc.

composer install
composer plugin-deps:prod
composer migrate
```

**First-time database:** either open **`/install.php`** in a browser (empty DB), or ensure migrations have run, then **remove `public/install.php` in production**.

Point your web server **document root** at **`public/`** (e.g. `public/index.php` as front controller).

## Plugin dependencies

Plugins that ship their own `composer.json` (e.g. Stripe store) keep dependencies under `plugins/<name>/vendor/`, which is **not** committed. After `composer install`, run:

```bash
composer plugin-deps:prod
```

For development (includes dev deps in plugins): `composer plugin-deps`.

## Useful Composer scripts

| Command | Purpose |
|--------|---------|
| `composer migrate` | Apply SQL migrations in `database/migrations/` |
| `composer test` | Smoke tests + layout / plugin health checks |
| `composer phpstan` | Static analysis |
| `composer plugin-deps:prod` | Install all plugin `vendor/` trees (production) |
| `composer cache:clear` | Clear internal caches |
| `composer smoke:staging` | HTTP smoke for `/`, `/kb`, `/forum`, `/shop` (set `STRUXA_SMOKE_BASE_URL`) |
| `composer serve` | PHP built-in server on `http://127.0.0.1:8080` |

### Staging smoke & Playwright

After deploy (or locally with `composer serve` + migrated DB):

```bash
STRUXA_SMOKE_BASE_URL=https://your-staging.example composer smoke:staging
```

Optional: `STRUXA_SMOKE_STRICT=0` allows HTTP 404 on routes; `STRUXA_SMOKE_SKIP=/forum` skips paths.

Playwright (public routes only):

```bash
npm install
npx playwright install chromium
STRUXA_E2E_BASE_URL=https://your-staging.example npm run e2e
```

Admin **Site health** includes a **Stack versions & sync** table (CMS, theme, plugins).

## CI

GitHub Actions (`.github/workflows/ci.yml`) runs `composer install`, `composer plugin-deps:prod`, `composer test`, and `composer phpstan` on PHP 8.2 and 8.3.

Optional workflow `.github/workflows/staging-smoke.yml` runs `composer smoke:staging` when `STRUXA_SMOKE_BASE_URL` is configured as a repository secret.

## Pushing code (GitHub)

From your project directory:

```bash
git status
git add -A
git commit -m "Describe the change in one line"
git pull --rebase origin main   # if others push to main; skip if you’re sure you’re up to date
git push origin main
```

Use a **feature branch** if you prefer pull requests:

```bash
git checkout -b my-feature
# … edit, commit …
git push -u origin my-feature
```

Then open a PR on GitHub into `main`.

**Remote:** if `origin` is not set yet:

```bash
git remote add origin git@github.com:struxa/struxa.git
# or: git remote add origin https://github.com/struxa/struxa.git
```

Run **`composer test`** (and **`composer phpstan`** locally) before pushing; CI runs the same checks.

## Deployment notes

- Never commit **`.env`** or **`public/uploads/`** user media; use **`.env.example`** as a template.
- **Plugins** are not in this git repo; install from **Admin → Browse catalog** (`struxa-dist/` + struxapoint.com). Core ships **`themes/struxa-theme/`**.
- For a file bundle aimed at FTP merges, see **`scripts/build-safe-update-zip.sh`** and **`scripts/FTP_UPDATE_README.txt`**.

## Documentation

- Overview: [`docs/README.md`](docs/README.md)
- Themes: [`docs/themes.md`](docs/themes.md)
- Plugin dependencies: [`docs/plugins-dependencies.md`](docs/plugins-dependencies.md)

## License

Add a **`LICENSE`** file at the repo root when you decide how you want to distribute Struxa (e.g. GPL-2.0-or-later for a WordPress-like posture, or MIT for a more permissive grant).

---

**Struxa** — Slim/Twig CMS · [struxapoint.com](https://struxapoint.com)

# Struxa distribution catalog (`struxa-dist`)

This folder is the **source for the public theme and plugin registry** used by Struxa CMS sites at install time.

**Live URL (default in CMS):** `https://struxapoint.com/struxa-dist/repo.json`

## What gets published (GitHub / struxapoint.com)

The public catalog is controlled by **`publish.json`** in this folder (default: **default** theme only, **no plugins**). Run `./scripts/build-struxa-dist.sh` after changing themes/plugins in the CMS repo; only allowlisted ZIPs and `repo.json` entries are kept.

**Note:** `publish.json` does **not** remove `plugins/` from the main git repo. CMS auto-updates and safe FTP ZIPs skip `plugins/` (see repo root `.gitattributes` and `CmsSelfUpdater`). Plugin source can stay in git for development; production sites install plugins from the catalog only.

To publish more later, edit `publish.json` (e.g. add theme slugs or `"include_plugins": true`) and rebuild.

## Separate Git repository (optional)

Create a dedicated repo, e.g. `struxa/struxa-dist` or `struxapoint/struxa-dist`, containing:

```
struxa-dist/
  repo.json          # catalog index (themes + plugins)
  zips/              # HTTPS-downloadable ZIP packages
  README.md
```

Deploy that repo to your web root so `repo.json` and `zips/*.zip` are served over **HTTPS** on `struxapoint.com` (or a CDN).

The **Struxa CMS** git repo stays the core product; **this** repo is only distributable themes/plugins (and ZIPs), similar to a package registry.

## `repo.json` shape

```json
{
  "catalog_version": 1,
  "themes": [
    {
      "slug": "my-theme",
      "name": "My theme",
      "version": "1.0.0",
      "description": "…",
      "author": "…",
      "download_url": "https://struxapoint.com/struxa-dist/zips/my-theme.zip"
    }
  ],
  "plugins": [
    {
      "slug": "my-plugin",
      "name": "My plugin",
      "version": "1.0.0",
      "description": "…",
      "author": "…",
      "requires_cms_version": "1.0.63",
      "download_url": "https://struxapoint.com/struxa-dist/zips/my-plugin.zip"
    }
  ]
}
```

## ZIP layout

### Themes

ZIP must unpack to a folder with `theme.json`, `views/`, and `assets/`. Slug in `theme.json` must match the catalog `slug`.

### Plugins

ZIP must contain `plugin.json` at the **root of the archive** or inside **one** top-level folder. The manifest `"slug"` must match the catalog `slug`. After install, files live at `plugins/{slug}/` on the site (folder name = slug).

Build a plugin ZIP from the plugin directory:

```bash
cd plugins/mailing-list-plugin
zip -r ../mailing-list-plugin.zip . -x '*.git*'
# Upload to zips/mailing-list-plugin.zip on struxapoint.com
```

## CMS admin

| Area | Action |
|------|--------|
| **Themes → Browse catalog** | Reads `themes[]` from the catalog |
| **Extensions → Plugins → Browse catalog** | Reads `plugins[]` from the same catalog |

## Environment variables (on each CMS site)

| Variable | Purpose |
|----------|---------|
| `STRUXA_DIST_CATALOG_URL` | Preferred: single URL for both themes and plugins |
| `STRUXA_THEME_CATALOG_URL` | Legacy override (themes); also used if dist URL unset |
| `STRUXA_PLUGIN_CATALOG_URL` | Optional plugin-only override |
| `STRUXA_THEME_DOWNLOAD_HOSTS` | Extra allowed ZIP hosts (comma-separated hostnames) |

Local fallback for development: copy `storage/dist-catalog.example.json` to `storage/dist-catalog.json`.

## Build from the CMS repo

```bash
./scripts/build-struxa-dist.sh
```

This refreshes **`zips/*.zip`** from `themes/` and `plugins/` and regenerates **`repo.json`**. Plugin ZIPs exclude `vendor/` (run `composer plugin-deps` on the site after installing Stripe store).

See **`PUBLISH.md`** for GitHub + struxapoint.com upload steps.

## Publishing workflow

1. Develop theme/plugin in the main Struxa repo (or its own repo).
2. Bump `version` in `theme.json` / `plugin.json`.
3. Run `./scripts/build-struxa-dist.sh`.
4. Commit and push this repo (or upload to struxapoint.com).
5. Sites refresh catalog on next **Browse catalog** page load.

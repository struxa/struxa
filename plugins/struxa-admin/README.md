# Struxa Catalog Admin (`struxa-admin`)

Community **plugin and theme** submissions for [struxapoint.com](https://struxapoint.com), with admin review and publishing to the public **`struxa-dist`** catalog (`repo.json` + `zips/*.zip`).

Repository: [github.com/struxa/admin](https://github.com/struxa/admin) — install as `plugins/struxa-admin/` on the Struxa main site.

## Features

- **Public browse** — `/plugins` and `/themes` (for visitors without a CMS install)
- **Submit** — GitHub repo URL + optional screenshot (no ZIP uploads to struxapoint)
- **Pre-flight validation** — Fetches `plugin.json` / `theme.json` from GitHub; checks slug, required fields, theme `views/` + `assets/`
- **Admin review** — Approve builds ZIP from GitHub, updates `struxa-dist/publish.json` and regenerates `repo.json`
- **Live catalog** — Same registry used by **Admin → Extensions → Browse catalog**

## Install

1. Copy this folder to `plugins/struxa-admin/` on your Struxa site.
2. **Extensions → Plugins** → activate **Struxa Catalog Admin** (runs DB migration).
3. Open **Catalog settings** and confirm:
   - **dist root** points at your published `struxa-dist/` directory
   - **ZIP base URL** is `https://struxapoint.com/struxa-dist/zips`
   - **Public site URL** is your struxapoint origin (for screenshot URLs)
4. Optional: set **GitHub token** for higher API rate limits.

## Cron / publishing

After approvals, `repo.json` is written immediately. Deploy `struxa-dist/` to production (rsync, etc.) — see `struxa-dist/PUBLISH.md` in the CMS repo.

You can also run **Regenerate repo.json** from catalog settings after manual ZIP changes.

## Requirements

- PHP 8.2+, extensions: `json`, `curl`, `zip`
- Writable `struxa-dist/` (or configured dist root)
- Public GitHub repositories only

## License

MIT

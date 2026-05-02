# Frontend and marketing

## Single source of truth (marketing)

The **canonical** public marketing experience is **Twig**, not hand-maintained static HTML at the repository root.

| Concern | Location |
| -------- | -------- |
| Document shell (meta, fonts, core + theme CSS, scripts) | `templates/public/root.twig` |
| Global chrome (atmosphere, header, mobile nav) | `partials/_shell.twig`, `partials/_header.twig` |
| Footer | `partials/_footer.twig` |
| Homepage route | `GET /` in `bootstrap/web_app.php` → **`page/home.twig` in the active theme** (not core `templates/`) |
| Landing sections (hero, how-it-works, offerings, showcase, marquee, stack) | `templates/partials/marketing/_landing_*.twig`, composed by `partials/_landing_main.twig` |
| Core marketing styles / JS | `public/css/styles.css`, `public/js/main.js` |
| Theme overlay (storefront, content pages) | Active theme under `themes/{slug}/` — see `docs/themes.md` |

The file **`index.html` at the repo root** is a **pointer only** (explains that Twig is canonical). It is not a second homepage.

### Optional static HTML export

To generate HTML that matches the live homepage (for demos or mirrors), run:

```bash
composer render-marketing-preview
```

This executes `bin/render-marketing-static.php`, boots the same Slim app as production, issues an internal `GET /`, and writes **`public/marketing-preview.html`**. That file is **generated** — edit Twig (and related assets), then re-run the command. It is listed in `.gitignore` so local snapshots are not committed by default; remove the ignore entry if you intentionally version a mirror.

Custom output path:

```bash
php bin/render-marketing-static.php --output=/path/to/out.html
```

### Rules for contributors

1. **Do not** add a second copy of marketing sections in static HTML at the root (beyond the stub `index.html`).
2. **Do** change core marketing in `templates/partials/marketing/` where applicable; theme home is **`themes/{active}/views/page/home.twig`**.
3. **Do** use **Menus** in admin for header/footer links so nav stays data-driven where possible.
4. **Storefront vs marketing assets** — Anything rendered through the **theme** shell (content types, theme pages, etc.) must use **theme** assets only; do not point theme templates at **`/css/styles.css`**. See **`docs/themes.md` → [Storefront Asset Boundary](themes.md#storefront-asset-boundary)**.

See also `docs/themes.md` for the public layout contract (`public/root.twig` vs theme `layouts/base.twig`).

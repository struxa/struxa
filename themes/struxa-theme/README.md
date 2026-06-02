# Struxa Vision (`struxa-theme`)

Light SaaS marketing theme for Struxa CMS — warm off-white canvas, orange accents, two-tier header, hero illustration, and content archive cards.

## Install

1. Copy this folder to `themes/struxa-theme/` on your Struxa site, **or**
2. **Appearance → Themes → Browse catalog** when published on [struxapoint.com/struxa-dist/repo.json](https://struxapoint.com/struxa-dist/repo.json), **or**
3. Clone from [github.com/struxa/struxa-theme](https://github.com/struxa/struxa-theme)

Activate under **Appearance → Themes**.

## Homepage (content types + page builder)

The Vision layout is driven by **content types**, not hard-coded Twig copy:

| Content type | Slug | Purpose |
|--------------|------|---------|
| Homepage hero | `homepage-hero` | Badges, headline, lead, CTAs, **featured image** (your illustration) |
| Trust logos | `trust-logos` | Labels in the trust bar row |

The public homepage should be a **CMS page** (e.g. slug `home`) with two blocks:

1. **Vision: Hero from content type** → `homepage-hero` / entry `main`
2. **Vision: Trust bar** → `trust-logos`

**Quick setup (local/demo):**

```bash
php themes/struxa-theme/bin/provision-home.php
```

Or import blueprint **Struxa Vision: homepage hero + trust bar** from **Admin → Tools → Blueprints**, then set **Settings → Public site homepage** to **Home**.

If no CMS homepage is set, `page/home.twig` falls back to the same content types.

## Theme settings (`theme.json`)

| Key | Purpose |
|-----|---------|
| `accent` | Primary orange (buttons, badges) |
| `topbar_location` | Top utility bar location label |
| `topbar_phone` | Top utility bar support label |
| `hero_primary_label` / `hero_primary_url` | Hero CTA |
| `trust_headline` | Text above capability pills |

## Menus

Assign **header** and **footer** menu locations in **Appearance → Menus**. Header links appear in the main navbar; footer links in the site footer.

## Storefront boundary

All CSS/JS loads via `theme_asset()`. Do not link core marketing `/css/styles.css` from these templates.

## Repository

Updates: set `repository_url` in `theme.json` and use **Themes → Update** when your CMS supports GitHub theme updates, or pull from GitHub manually.

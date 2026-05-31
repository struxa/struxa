# Developer documentation

This folder describes how the CMS is structured and how to extend it locally.

| Document | Topics |
|----------|--------|
| [structure.md](structure.md) | Folders, bootstrap, routing, services |
| [themes.md](themes.md) | `theme.json`, inheritance, assets, Twig |
| [plugins.md](plugins.md) | `plugin.json`, routes, service providers |
| [blueprints.md](blueprints.md) | Importable structure packages |
| [content-types.md](content-types.md) | Types, fields, entries, public routes |
| [commerce.md](commerce.md) | Content-type products, Stripe Checkout, orders |
| [mobile.md](mobile.md) | Mobile app bootstrap API and admin settings |
| [mobile-app/README.md](../mobile-app/README.md) | Expo client app (Phase 2+) |
| [docker.md](docker.md) | Local containers and environment |
| [migrations.md](migrations.md) | SQL migrations workflow |

Run `php bin/cms.php` for CLI help, `composer test` for the lightweight test runner, and `composer migrate` to apply schema changes.

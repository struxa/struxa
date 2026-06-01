# Mobile app integration

Struxa sites can expose a **public bootstrap API** for the official Struxa client app (Expo/React Native). Users add one or more site URLs in the app; each site loads its own branding, navigation, and feature flags from that site's bootstrap endpoint.

## Phase 1 (CMS) — implemented

### Endpoints

| Method | Path | Auth | Purpose |
|--------|------|------|---------|
| `GET` | `/api/v1/mobile/bootstrap` | None | Full bootstrap payload for client apps |
| `GET` | `/.well-known/struxa.json` | None | Minimal discovery (bootstrap URL + CMS version) |
| `GET` | `/api/v1/mobile/content/{typeSlug}/entries` | None | Published entry list (paginated) |
| `GET` | `/api/v1/mobile/content/{typeSlug}/entries/{entrySlug}` | None | Published entry detail |
| `POST` | `/api/v1/mobile/auth/login` | None | Email/password login → JWT + refresh token |
| `POST` | `/api/v1/mobile/auth/register` | None | Create PHPAuth account |
| `POST` | `/api/v1/mobile/auth/refresh` | None | Rotate refresh token, new access token |
| `POST` | `/api/v1/mobile/auth/logout` | None | Revoke refresh token |
| `GET` | `/api/v1/mobile/auth/me` | Bearer access token | Current user profile |
| `GET` | `/api/v1/mobile/commerce/products` | None | Paginated product catalog |
| `GET` | `/api/v1/mobile/commerce/products/{entrySlug}` | None | Product detail |
| `GET` | `/api/v1/mobile/commerce/config` | None | Currency, country requirements |
| `POST` | `/api/v1/mobile/commerce/cart/quote` | None | Cart totals (lines in JSON body) |
| `POST` | `/api/v1/mobile/commerce/checkout` | Optional Bearer | Stripe Checkout URL |
| `GET` | `/api/v1/mobile/commerce/orders` | Bearer | Customer order list |
| `GET` | `/api/v1/mobile/commerce/downloads` | Bearer | All active digital downloads for customer |
| `GET` | `/mobile/add` | None | Web landing page with add-site deep link |
| `GET` | `/mobile/add/qr.svg` | None | QR code SVG for deep link |
| `GET` | `/api/v1/mobile/commerce/orders/{orderNumber}` | Bearer | Order detail (includes `digital_downloads`) |

When mobile bootstrap is **disabled** in admin:

- Bootstrap returns `403` with `{ "ok": false, "error": "mobile_disabled" }`
- Well-known returns `404`

### Admin

**Mobile app → Settings & content** (`/admin/mobile`)

- Enable/disable bootstrap API
- **Content types to release** — limit which public content types appear in Browse (default: all types with a public route)
- **App sections** — toggle Browse, Search, Shop, and Account tabs (respects site commerce/search settings)
- Welcome copy, QR / add-site links, optional custom tab bar JSON
- Bootstrap JSON preview

Legacy URL `/admin/settings/mobile` still works and saves to the same settings.

### Bootstrap payload (schema v1)

Successful bootstrap response:

```json
{
  "ok": true,
  "data": {
    "schema_version": 1,
    "cms_version": "1.1.44",
    "site": { "name": "…", "tagline": "…", "url": "https://…", "language": "en" },
    "branding": {
      "logo_url": "https://…/uploads/…",
      "favicon_url": "https://…/favicon.svg",
      "accent_color": "#8b7cf6",
      "theme_slug": "default"
    },
    "features": {
      "commerce": true,
      "search": false,
      "comments": true,
      "auth": {
        "login_path": "/login",
        "register_path": "/register",
        "google_sso": false,
        "collect_username": false
      }
    },
    "api": {
      "rest_base": "https://…/api/v1",
      "graphql": "https://…/api/v1/graphql",
      "bootstrap": "https://…/api/v1/mobile/bootstrap"
    },
    "mobile": {
      "welcome_title": "…",
      "welcome_message": "…",
      "tabs": [
        { "id": "home", "label": "Home", "type": "home" },
        { "id": "browse", "label": "Browse", "type": "content" },
        { "id": "shop", "label": "Shop", "type": "shop" }
      ],
      "add_site_deeplink": "struxa://add-site?url=https%3A%2F%2F…",
      "add_site_web_url": "https://…/mobile/add"
    },
    "navigation": {
      "header": [{ "label": "Blog", "href": "https://…/c/post", "target": "" }]
    },
    "content_types": [
      {
        "slug": "post",
        "name": "Posts",
        "description": "",
        "route": "/c/post",
        "supports_featured_image": true
      }
    ],
    "commerce": {
      "currency": "gbp",
      "shop_title": "Shop",
      "shop_path": "/shop",
      "product_type_slug": "product",
      "needs_checkout_country": false
    }
  }
}
```

`commerce` is omitted when commerce is disabled.

### Plugin filter

Register `FilterHook::MOBILE_BOOTSTRAP` (`mobile.bootstrap`) to adjust the payload before it is returned:

```php
use App\Filter\FilterHook;

$context->addFilter(FilterHook::MOBILE_BOOTSTRAP, function (array $payload, array $ctx): array {
    $payload['mobile']['tabs'][] = [
        'id' => 'scores',
        'label' => 'Scores',
        'type' => 'plugin',
    ];
    return $payload;
});
```

Declare `mobile.bootstrap` in your plugin manifest `hooks.filters` array. Example plugin tab:

```php
$payload['mobile']['tabs'][] = [
    'id' => 'scores',
    'label' => 'Scores',
    'type' => 'plugin',
    'plugin_slug' => 'my-plugin',
    'screen' => 'leaderboard',
    'url' => $siteUrl . '/my-plugin/mobile',
];
```

### Settings keys (`cms_settings`)

| Key | Default | Description |
|-----|---------|-------------|
| `mobile_app_enabled` | `1` | Bootstrap on/off |
| `mobile_app_welcome_title` | `` | Override welcome title |
| `mobile_app_welcome_message` | `` | Override welcome message |
| `mobile_app_include_footer_nav` | `0` | Include footer menu |
| `mobile_app_tabs_json` | `` | Custom tab bar JSON |

Migration: `database/migrations/055_mobile_app.sql`

### Security notes

- Bootstrap and mobile content endpoints are **read-only** and **unauthenticated** — published entries only; never include API keys, secrets, or drafts.
- Staff write access and draft previews remain on `/api/v1` (API key).
- Mobile JWT auth uses existing PHPAuth users; access tokens expire in 15 minutes; refresh tokens rotate on use.
- Google SSO deep links are planned for a later phase.
- Do not embed write-scoped API keys in mobile app binaries.

### Testing locally

```bash
composer migrate
curl -s http://localhost:3439/api/v1/mobile/bootstrap | jq .
curl -s http://localhost:3439/.well-known/struxa.json | jq .
curl -s 'http://localhost:3439/api/v1/mobile/content/post/entries?per_page=5' | jq .
curl -s 'http://localhost:3439/api/v1/mobile/commerce/products?per_page=5' | jq .
```

See [mobile-phases.md](mobile-phases.md) for the full roadmap.

## Phase 2 — Expo app (`mobile-app/`)

The client app lives in **`mobile-app/`** at the repo root.

```bash
cd mobile-app
npm install
npm start
```

Add your site URL in the app; it loads bootstrap and shows a themed tab shell. Use the **Browse** tab to read published content (Phase 3). See [mobile-app/README.md](../mobile-app/README.md).

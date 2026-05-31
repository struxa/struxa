# Mobile app integration

Struxa sites can expose a **public bootstrap API** for the official Struxa client app (Expo/React Native). Users add one or more site URLs in the app; each site loads its own branding, navigation, and feature flags from that site's bootstrap endpoint.

## Phase 1 (CMS) — implemented

### Endpoints

| Method | Path | Auth | Purpose |
|--------|------|------|---------|
| `GET` | `/api/v1/mobile/bootstrap` | None | Full bootstrap payload for client apps |
| `GET` | `/.well-known/struxa.json` | None | Minimal discovery (bootstrap URL + CMS version) |

When mobile bootstrap is **disabled** in admin:

- Bootstrap returns `403` with `{ "ok": false, "error": "mobile_disabled" }`
- Well-known returns `404`

### Admin

**Site → Mobile app** (`/admin/settings/mobile`)

- Enable/disable bootstrap
- Optional welcome title and message (defaults to site name / tagline)
- Include footer menu in navigation payload
- Optional custom tab bar JSON (advanced)

### Bootstrap payload (schema v1)

Successful bootstrap response:

```json
{
  "ok": true,
  "data": {
    "schema_version": 1,
    "cms_version": "1.1.41",
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
      ]
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
      "shop_path": "/shop"
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

Declare `mobile.bootstrap` in your plugin manifest `hooks.filters` array.

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

- Bootstrap is **read-only** and **unauthenticated** — it must never include API keys, secrets, or draft content.
- Content and commerce data for the app still use `/api/v1` (API key) or future mobile JWT auth (Phase 4).
- Do not embed write-scoped API keys in mobile app binaries.

### Testing locally

```bash
composer migrate
curl -s http://localhost:3439/api/v1/mobile/bootstrap | jq .
curl -s http://localhost:3439/.well-known/struxa.json | jq .
```

See [mobile-phases.md](mobile-phases.md) for the full roadmap.

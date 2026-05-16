# Mailing list plugin

Create multiple mailing lists in the admin, collect subscribers with validated email addresses, and embed signup forms on the storefront.

## Setup

1. Ensure Struxa **1.0.63+** (plugin reserved URL segments).
2. Activate **Mailing list** under **Admin → Extensions** (runs `migrations/001_mailing_list_schema.sql` automatically).
3. **Admin → Extensions → Mailing lists** → create a list (name + slug).

## Admin

- **Lists** — name, slug, description, active/inactive.
- **Subscribers** — per-list email addresses (subscribed / unsubscribed status in DB; admin can remove rows).

Slugs must not collide with CMS routes; the plugin registers `mailing-list` as a reserved segment.

## Public signup

- **Subscribe page:** `GET /mailing-list/subscribe/{list-slug}` (active lists only).
- **POST** `/mailing-list/subscribe` with `list` (slug) and `email`. Optional `return_to` path (must start with `/`).

Email validation uses PHP `FILTER_VALIDATE_EMAIL`, normalizes to lowercase, max 190 characters. Rate limit: 12 sign-ups per hour per IP per list. Honeypot field: `website_url` (must be empty).

JSON response when `Accept: application/json` or `ajax=1` in the body.

## Embed in a theme

```twig
{% include '@plugin_mailing_list_plugin/public/partials/signup_form.twig' with {
  list_slug: 'newsletter',
  button_label: 'Subscribe'
} %}
```

## Uninstall

Deactivate/remove the plugin; with `uninstall.sql` present, tables `cms_mailing_list_lists` and `cms_mailing_list_subscribers` are dropped.

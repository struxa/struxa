# Blueprints

Blueprints are JSON documents that describe reusable CMS **structure**: content types (and optionally fields), menus, settings keys, and required plugin slugs. They are validated by `App\Blueprint\BlueprintSchemaValidator` (`cms_blueprint_version` must be `1.0`).

## Storage

Place blueprint files under **`storage/blueprints/`** (or supply paths via import tooling). Example files:

- **`blog.json`** — Blog-oriented starter (post-like type, categories/tags taxonomies).
- **`review-site.json`** — Review / rating oriented type.
- **`products-apps.json`** — Product catalog type (`/products`) with blurbs, **`media_seed`** images (Unsplash → media library + featured image), **`price_display`** on cards/detail, and three sample apps (SignalBridge, Campus Compass, Casa Lingua).

## Import workflow

Use the admin **Tools → Import / Export** (or blueprint screens) to apply a blueprint to the current database. Always review diffs and back up before importing on production.

Showcase packages live under **`storage/blueprints/`** (e.g. `blog.json`, `review-site.json`, `agency-business.json`): turn on **import entries** when applying so seeded posts, reviews, team members, and pages are created.

## Authoring rules

- **`content_types` is required** — must be a JSON **array** (use `[]` when the blueprint only adds pages, menus, or settings).
- Every content type needs a valid `slug` (`^[a-z0-9][a-z0-9\-]{0,62}$`) and non-empty `name`.
- `fields` must be a **list** of field objects (`field_key`, `label`, `field_type`, optional `sort_order`, etc.) — not a keyed map.
- `taxonomies` must be a **list** of taxonomy objects (`slug`, `name`, `taxonomy_type`, `terms`, …) when present.
- `settings` must be an object map of string keys to **string** values when present (merged into `cms_settings` on import).
- `pages` (optional): list of `{ title, slug, content, status }` — inserted before menus so `page_slug` menu items resolve.
- `content_entries` (optional): each entry may include `taxonomy_terms` as an object map `{ taxonomy_slug: [ term_slug, ... ] }` after types and taxonomies exist. Entries may set `featured_image_id` (existing media) or **`featured_image_media_slug`** to match an item in **`media_seed`** (processed immediately before entries on import).
- **`media_seed`** (optional): array of `{ "slug": "stable-key", "source_url": "https://images.unsplash.com/..." }`. On apply (not dry-run), each image is downloaded, stored under `public/uploads/blueprint-seed/`, inserted into `cms_media`, and the slug maps to the new id for `featured_image_media_slug`. Only the host **`images.unsplash.com`** is allowed; HTTPS only; size and MIME checks apply.
- `required_plugin_slugs` must be an array of strings when present.

## Public URLs after import

Types with `has_public_route` get **`/{typeSlug}`** (index) and **`/{typeSlug}/{entrySlug}`** (single). Taxonomy archives use **`/{typeSlug}/{taxonomySlug}/{termSlug}`**. The active theme can override Twig paths such as `content/blog/show.twig` (see the shipped **Struxa Vision** theme).

Extend the validator when you add new top-level blueprint sections so invalid packages fail fast.

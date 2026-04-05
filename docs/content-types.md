# Content types

## Model

- **`cms_content_types`** — Defines each model (name, slug, flags such as public route, SEO, featured image).
- **`cms_content_fields`** — Field definitions per type (`field_key`, `field_type`, labels, options).
- **`cms_content_entries`** — Rows per entry (title, slug, status, SEO columns, `featured_image_id`, workflow metadata).
- **Field values** — Stored in normalized tables keyed by field and entry (see migrations in `database/migrations/` for the exact schema).

## Admin UI

Routes in **`routes/admin_content.php`** provide CRUD for types, fields, and entries, gated by permissions (`manage_content_types`, `create_content`, `edit_content`, etc.).

## Public rendering

When `has_public_route` is enabled for a type, public URLs follow the configured pattern (see core public content routes). Twig templates under the active theme resolve `content/show.twig` (and related) using the entry and resolved field values.

Those storefront templates must follow the **Storefront Asset Boundary**: load styles and scripts via **`theme_asset()`** (and other theme helpers), not core marketing CSS such as **`/css/styles.css`**. See **`docs/themes.md` → [Storefront Asset Boundary](themes.md#storefront-asset-boundary)**.

## Taxonomies

Taxonomies link terms to entries via join tables. Admin routes under **`routes/admin_taxonomies.php`** manage vocabulary and terms; public archives use taxonomy-specific routes and theme templates such as `taxonomy/archive.twig`.

## Revisions

Entry (and page) revisions support compare/restore in admin for users with the appropriate permissions.

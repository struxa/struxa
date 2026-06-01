# Entry links (`entry_refs`)

Drupal-style **entity reference** fields for Struxa content types. Link one or many published entries (products, authors, locations, “featured in” lists) without custom plugins.

## Field type

In **Content types → Fields**, choose **Entry links (reference)** (`entry_refs`).

| Setting | Meaning |
|--------|---------|
| Target content type | Restrict picks to one type, or **any type with a public URL** |
| Maximum links | `1` = single reference; up to `100` for multi |
| Require public targets | When publishing, each linked entry must already be publicly visible |

Values are stored as a JSON array of entry IDs in display order, e.g. `[12,34]`.

## Admin entry editor

Use the search box on each Entry links field to find entries by title, slug, or ID. Selected items appear as a list; remove with **×**. Validation blocks self-links, wrong types, missing entries, and (when configured) unpublished targets.

## Public site & API

- Entry detail pages render linked titles as a `<ul>` when using default templates.
- **REST** `GET /api/v1/content-types/{slug}/entries/{slug}` includes `referenced_entries` on `entry_refs` fields (title, slug, `public_url`, `is_public`, etc.).
- **Twig:** `{% for ref in entry_refs_resolve(field_value) %}…{% endfor %}`

## Migration

Ensure migration `036_cms_content_fields_entry_refs.sql` has been applied (`composer migrate`) so `entry_refs` is allowed in `cms_content_fields.field_type`.

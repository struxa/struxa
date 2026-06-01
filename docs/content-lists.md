# Content lists (Views-lite)

Saved queries over content entries: filter by status, taxonomy term, and custom fields; sort and paginate; expose on the site, in Twig, or via the public API.

## Admin

**Content → Content lists** (`/admin/content-lists`) — requires `manage_content_types`.

1. Create a list: pick a **content type**, optional **statuses**, **taxonomy term**, up to **three field filters** (e.g. `score` > `4`), and **sort**.
2. Enable **Public page** for `/lists/{slug}`, **REST API**, or use in themes without a public page.
3. **Preview** runs the query with admin rules (drafts allowed when selected).

Run migration `058_content_lists.sql` (`composer migrate`).

## Storefront

- **URL:** `/lists/{slug}?page=2` (when “Public page” is enabled)
- **Theme override:** `themes/{theme}/views/content_lists/{slug}.twig` or `content_lists/show.twig`
- Default template reuses `content/_index_grid.twig` (same cards as type archives)

## Twig

```twig
{% set pack = content_list('top-reviews', 6) %}
{% for card in pack.entries %}
  <a href="/{{ pack.type.slug }}/{{ card.row.slug }}">{{ card.row.title }}</a>
{% endfor %}
```

Arguments: `content_list(slug, limit, page)`. Embeds use **published** visibility (storefront-safe).

## API

`GET /api/v1/content-lists/{slug}?page=1` — requires API key with `read` scope.

Response includes `list`, `meta`, and `data` (entry summaries). Draft statuses in the list definition require `read_drafts` unless “published only” is set.

## Field filter operators

| Field type | Operators |
|------------|-----------|
| number | eq, neq, gt, gte, lt, lte |
| text, textarea, select, url | eq, contains |
| boolean | eq |

## Sort

Entry columns: `published_at`, `updated_at`, `created_at`, `title`, or `field:{field_key}` for custom fields.

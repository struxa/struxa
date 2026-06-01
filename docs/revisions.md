# Revisions: compare and restore

Struxa stores a revision snapshot on each save for **pages** and **content entries**. Editors can see what changed and roll back without SQL or plugins.

## Where to find it

| Content | History | Compare |
|--------|---------|---------|
| Page | Page edit → **Recent revisions** → View all | **Tools** path: `/admin/pages/{id}/revisions` |
| Content entry | Entry edit sidebar → **Revisions** | `/admin/content-types/{typeId}/entries/{entryId}/revisions` |

From any revision row:

- **Compare to now** — older snapshot vs current saved content
- **vs previous** — adjacent snapshots in the list (newest first)
- **Restore** — replaces live content with that snapshot; the current version is snapshotted first

## Compare screen

Use the **Compare versions** picker (From = older, To = newer or “Current saved version”). The UI shows:

- A **What changed** table (title, slug, status, SEO fields, custom fields for entries, block counts when snapshotted)
- A **line diff** for body / full field text
- **Restore this version** to roll back to the **From** snapshot

Legacy URLs with `/revisions/compare/{revId}` redirect to `?from={revId}&to=current` (or `?other=` preserved as `to`).

## Retention

Revision retention limits are configured under **Tools → Maintenance** (see `RevisionRetentionSettings`).

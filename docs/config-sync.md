# Config sync (CMI-lite)

Drupal-style **configuration packages** without full Configuration Management: named scope bundles, semantic diff preview, and apply-after-review.

Admin: **Tools → Config sync** (`/admin/tools/config-sync`). Requires `manage_portability`.

## Packages (presets)

| Preset | Scopes |
|--------|--------|
| Content schema | `content_types` |
| Menus | `menus` |
| Site settings | `settings`, `meta` |
| Mobile app | `mobile` |
| Roles & permissions | `roles` |
| Commerce rules | `commerce` (zones, tax, coupons — not orders) |
| **Production → staging** | Types, menus, settings, meta, roles, mobile, commerce |
| Full structure | All of the above except entries |

Custom exports: check individual scopes on the export form (overrides the preset).

## File format

```json
{
  "cms_config_package_version": "1.0",
  "package_id": "agency-staging",
  "label": "Production snapshot",
  "exported_at": "2026-05-29T12:00:00+00:00",
  "source_environment": "production",
  "source_site_url": "https://example.com",
  "scopes": ["content_types", "menus", "..."],
  "structure": {
    "cms_structure_export_version": "1.1",
    ...
  }
}
```

Legacy **Import / export** JSON (`cms_structure_export_version` 1.0) still imports via Config sync preview.

## Agency workflow (production → staging)

1. On production, set **Site profile → Environment** to `production` (Blueprints page).
2. **Config sync → Export** → preset **Production → staging** → download JSON.
3. On staging, **Import with diff preview** → upload file → **Preview diff & validate**.
4. Review `+` / `~` / `−` lines and dry-run messages.
5. **Apply to database** on the preview page.

Saved copies can be written to `storage/config-packages/` on the source server and selected on import.

## Related tools

- **Blueprints** — full site snapshots (pages, redirects, entries, media seed).
- **Import / export** — manual scope checkboxes without named packages or diff UI.

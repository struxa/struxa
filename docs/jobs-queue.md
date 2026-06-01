# Background jobs & cron visibility

Struxa defers heavy work to a MySQL-backed queue (`cms_jobs`). Editors and site owners monitor it under **Tools → Background jobs** (`/admin/tools/jobs`).

## What you see

- **Cron heartbeats** — last `schedule:run` and `jobs:work` times (UTC), overdue scheduled publish count, recommended crontab lines
- **Queue counts** — pending, running, failed, completed in the last 24 hours
- **Job list** — filter by status, type, queue; paginated newest first
- **Job detail** — payload JSON, result summary, last error, retry / cancel actions

## CLI (required for processing)

```bash
# Enqueue recurring work (publish due + optional retention when auto-purge is on)
php bin/cms.php jobs:dispatch

# Process the queue (run on cron)
php bin/cms.php jobs:work --limit=20

# Quick status
php bin/cms.php jobs:status
```

Recommended cron:

```cron
*/15 * * * * cd /path/to/project && php bin/cms.php jobs:dispatch && php bin/cms.php jobs:work --limit=20
```

Alternatively, `schedule:run` applies due publish/unpublish directly (without the queue) and records its own heartbeat.

## Built-in job types

| Type | Purpose |
|------|---------|
| `schedule.publish_due` | Publish/unpublish entries and pages by schedule |
| `maintenance.purge_scheduled` | Retention purges (logs, tokens, revisions) |
| `media.compress_batch` | Re-encode library images in batches |
| `sitemap.warm` | Warm sitemap cache |

Plugins may register additional types via `Jobs::registerHandler()`.

## Admin actions

- **Retry** — requeues a failed job (resets attempts)
- **Cancel** — cancels a pending job
- **Recover stale running** — returns jobs stuck in `running` after a worker crash
- **Purge completed** — deletes old completed rows (default 30+ days)

Jobs are **not** executed from the admin UI; only the CLI worker processes them.

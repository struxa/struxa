# Migration workflow

## Location

SQL files live in **`database/migrations/`**. File names should sort lexicographically in application order (e.g. `001_...sql`, `010_...sql`).

## Applying migrations

- **`composer migrate`** or **`php bin/migrate.php`** or **`php bin/cms.php migrate`**

The migrator records applied files in **`cms_migrations`** (created by early migrations) and skips already-applied names.

## Authoring new migrations

1. Add a new `.sql` file with the next sequence number.
2. Use `CREATE TABLE IF NOT EXISTS`, `ALTER TABLE ... ADD COLUMN` with existence checks where practical, so re-runs on partial state fail safely.
3. Test on a fresh database and on an already-migrated copy.
4. Never rewrite history of migrations that have shipped to production; add corrective follow-up migrations instead.

## Plugins

Core migrator only scans **`database/migrations/`**. Plugin DDL should either ship as documented manual SQL under `plugins/{slug}/migrations/` or be applied through your deployment process — keep that explicit in plugin README files.

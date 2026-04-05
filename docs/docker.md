# Local Docker setup

## Prerequisites

Docker and Docker Compose on your machine.

## Typical flow

1. Copy **`.env.example`** to **`.env`** and adjust database variables if needed (`DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`).
2. From the project root, run **`docker compose up -d`** (or your compose file’s equivalent).
3. Wait until the MySQL service is healthy.
4. Run **`composer migrate`** or **`php bin/cms.php migrate`** to apply `database/migrations/`.
5. Open the app URL (often `http://localhost:8080` if mapped in compose).

## Verifying connectivity

Run **`php bin/cms.php check`** — loads `.env` when present and attempts a read-only `SELECT 1` against MySQL.

## Troubleshooting

- If the browser shows **Database unavailable**, confirm containers are running, credentials match `.env`, and the database exists.
- If admin tables are missing, migrations have not been applied.
- See **`public/index.php`** error page text for the same hints when the DB connection fails at runtime.

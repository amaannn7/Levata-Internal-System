# PostgreSQL Deployment Guide

This app's storage layer moved from JSON files in `data/` to PostgreSQL. Every
endpoint that used to read/write `data/*.json` now goes through `db.php`
instead — nothing else in `api.php`/`sow.php`/`cp.php`/`jobs.php`/`tasks.php`/
`support.php` changed behaviourally.

> **`db.php` is gitignored.** It holds real DB credentials and must be
> deployed by hand via cPanel File Manager, never via git. Copy it from
> `db.example.php` (which *is* committed) and fill in real values.

---

## How it works

`api.php` does `require_once __DIR__ . '/db.php';` unconditionally — there is
no JSON-file fallback anymore. `db()` lazily opens a PDO connection and calls
`dbBootstrap()`, which runs `CREATE TABLE IF NOT EXISTS …` for every table on
first connection. **No manual SQL is needed** — just point `db.php` at an
empty database and the schema creates itself.

Storage design (see `db.php` for full detail):
- `users` — real table (id, name, email, password, is_admin, is_super_admin,
  timestamps + a `data` JSONB column for extras like device tokens).
- `store_blobs` — one JSONB row per shared store (`admin_config`, `documents`,
  `jobs`, `tasks`, `tickets`, `user_data:<user_id>`). This is the source of
  truth, a drop-in replacement for the old JSON files.
- `documents` / `jobs` / `tasks` / `tickets` — reporting-only projections with
  real columns (status, client, type, dates, …), refreshed from the blob on
  every save. Not read from by the app; there for future SQL reporting.
- `activity_pings` — real table backing the Users → Session Activity view.

## Phase 0 — Create the Postgres database (cPanel)

1. cPanel → **PostgreSQL Databases**.
2. Create database, e.g. `levatadb` (cPanel will likely prefix it, e.g.
   `levatahq_levatadb`).
3. Create user, e.g. `levata` (may become `levatahq_levata`), with a strong
   password.
4. **Add the user to the database** with ALL privileges.
5. Note the final DB name + username exactly as cPanel created them.

## Phase 1 — Configure db.php on the server

Locally, copy the template and edit it:

```bash
cp db.example.php db.php
```

Edit the constants in `db.php` to match Phase 0 exactly:

```php
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'levatahq_levatadb');
define('DB_USER', getenv('DB_USER') ?: 'levatahq_levata');
define('DB_PASS', getenv('DB_PASS') ?: 'the-real-password');
```

Upload this `db.php` to the app root via **cPanel File Manager** (drag/drop or
the upload button) — do not commit it, do not push it via git.

## Phase 2 — Deploy the application code (git)

Everything except `db.php` deploys normally:

```bash
git push
```

Then in cPanel: **Git Version Control** → Update from Remote → Deploy HEAD
Commit (or however this deployment is wired up).

## Phase 3 — First run seeds itself

There is no migration script to run. The first request to `api.php` on the
new database will:
1. Run `dbBootstrap()` — creates every table if it doesn't exist yet.
2. Seed a default admin user if the `users` table is empty:
   `admin@levatahq.com` / `password` (change this immediately after first login).

Just load the app in a browser and log in with those credentials.

## Phase 4 — Verify

- Login works with the seeded admin (or your real users, once created).
- Leads: add a lead manually, confirm it appears in the Leads list.
- Documents: create a SOW or Cost Proposal, confirm it saves and lists.
- Job Registry: create a job, confirm the invoice schedule generates.
- Tasks / Support tickets: create one of each.
- Admin → **Users**: the Session Activity table should start populating after
  a minute of use (heartbeat pings every 60s while a tab is active).

## Rollback

The previous JSON-file storage code is preserved in git history (the commit
before this migration). To roll back: `git revert` the migration commit (or
check out the prior commit for `api.php`/`sow.php`/`jobs.php`/`tasks.php`/
`support.php`), remove the `require_once __DIR__ . '/db.php'` line, and the
app will resume reading/writing `data/*.json` as before. Note: any data
entered while running on Postgres will NOT be reflected back in the JSON
files — the two storage layers do not sync with each other.

## Local development

Local dev uses the exact same `db.php` / Postgres path as production — there
is no JSON fallback mode. To set up a local database:

```sql
CREATE ROLE levata WITH LOGIN PASSWORD 'levata_local_dev';
CREATE DATABASE levata_local OWNER levata;
```

(Or do the equivalent in pgAdmin: create a login role, then a database owned
by it.) Then `cp db.example.php db.php` and adjust credentials if you used
different values than the defaults already in the template. Run:

```bash
php -S localhost:8000
```

The first request bootstraps the schema and seeds the default admin, same as
production.

# DBNova

[![CI](https://github.com/vivek-mistry/dbnova/actions/workflows/ci.yml/badge.svg)](https://github.com/vivek-mistry/dbnova/actions/workflows/ci.yml)

A self-hosted MySQL manager: a faster, more trustworthy alternative to phpMyAdmin.

DBNova manages **one MySQL server** running on the same machine or VPS. You sign in with a MySQL account, and that account's grants decide what you can do. It's built for everyday browsing and for importing and exporting production-sized databases (10 GB and more) without running out of memory.

> **Status:** in active development. Phases 0–7 (through the import and export engines) are done, and monitoring (Phase 8) is in review. Next up: hardening and a release with Docker support. See [Roadmap](#roadmap).

## Features

- **Sign in with your MySQL account.** DBNova has no separate user accounts or passwords. Credentials are kept encrypted in your session only.
- **Databases.** List, create, rename and drop (dropping needs the typed name plus your password), with sizes, table counts and collations.
- **Table browser.**
    - Server-side paging (keyset where possible) that stays fast on 10M-row tables.
    - A virtualized grid that tells NULL from an empty string.
    - Inline edit, insert and delete on tables with a primary or unique key.
- **Schema management.** Create and alter tables, columns, indexes and foreign keys. You see the generated SQL before anything runs, and online ALTERs are used where MySQL supports them.
- **SQL editor.**
    - CodeMirror with schema autocomplete.
    - Multi-statement runs with a row cap, and a cancel button for long queries.
    - Query history and saved queries.
- **Large imports** (`.sql`, `.sql.gz`, `.zip`):
    - Chunked, resumable uploads.
    - A streaming check that rejects truncated, multi-database or non-dump files before anything reaches MySQL.
    - A queued import that reports honest progress (bytes sent, never "% committed"), with cancel, recovery and MySQL errors at the line they occur in your file.
- **Exports.**
    - **SQL dumps:** a whole database or chosen tables to `.sql` or `.sql.gz` via `mysqldump`, in a consistent snapshot, ready to re-import anywhere.
    - **CSV:** one table, or the full result of a SQL editor query, to `.csv` or `.csv.gz`. NULL stays distinguishable from empty text, and query exports are read-only.
    - Exports run in a background job, with progress and cancel, and are downloaded through short-lived signed links.
- **Monitoring and maintenance.**
    - A live process list with Stop query / Kill connection, plus server variables and status counters.
    - Dashboard charts for queries per second, connections and buffer pool use.
    - OPTIMIZE / ANALYZE / CHECK / REPAIR on one or many tables, and a data vs index size breakdown.
- **Per-user preferences.** Rows per page, the SQL row limit and the query timeout, within maximums set in `.env`.
- **Safety.** A read-only mode enforced in the data layer, typed confirmations for destructive actions, and a filterable audit log that never stores row values.

### Measured on a laptop (MySQL 8.0.39)

| Operation                                    | Result                                        |
| -------------------------------------------- | --------------------------------------------- |
| Import 2 GB `.sql` (20.1M rows)              | 117 s, worker memory flat (+6 MB)             |
| Export 10M rows / 3.7 GB of SQL to `.sql.gz` | 26 s, worker memory flat (+4 MB), 261 MB file |
| Export a 10M-row table to `.csv`             | 23 s, worker memory flat (+2 MB)              |
| Export → re-import round trip                | identical row counts and table checksums      |

## Tech stack

Laravel 13 · Inertia v3 · Vue 3 · TypeScript · Tailwind CSS 4 · shadcn-vue · Pest.

- **Managed server:** raw PDO for interactive work, and the `mysql` / `mysqldump` CLI for bulk work. Laravel's DB layer is never pointed at your MySQL server.
- **DBNova's own data:** SQLite holds the app's data, queue and cache.

## Requirements

- PHP 8.3+ with `pdo_mysql`, `pdo_sqlite`, `mbstring`, `openssl`, `zip`, `zlib`, `pcntl` and `posix`
- MySQL 8.0+ (5.7 best-effort), plus the `mysql` and `mysqldump` client binaries, ideally the same version as the server
- Node 20+ (build only)
- A queue worker and the scheduler (see below)

## Getting started

```bash
git clone https://github.com/vivek-mistry/dbnova.git && cd dbnova
composer install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite && php artisan migrate
npm install && npm run build
```

Point DBNova at your server and client binaries in `.env`:

```dotenv
DBNOVA_DB_HOST=127.0.0.1
DBNOVA_DB_PORT=3306
DBNOVA_DB_SOCKET=
DBNOVA_MYSQL_BIN=/usr/bin/mysql            # full paths: shell aliases are not visible to PHP
DBNOVA_MYSQLDUMP_BIN=/usr/bin/mysqldump
DBNOVA_READ_ONLY=false
```

`.env` only says **where** the server is. It never holds a MySQL username or password.

Check the setup, then run the app with a worker and the scheduler:

```bash
php artisan dbnova:install                                   # checks PHP, binaries, MySQL, storage and the worker
composer run dev                                             # app + Vite during development
php artisan queue:work --queue=imports,exports,default       # imports and exports run here
php artisan schedule:work                                    # recovery and cleanup (cron: schedule:run every minute)
```

Open the app and sign in with a MySQL account. A dedicated MySQL user is recommended over `root`.

Production setup (nginx, supervisor, PHP limits) is described in [`.claude/docs/INSTALL.md`](.claude/docs/INSTALL.md).

## Testing

```bash
composer ci:check        # format, lint, types, PHPStan and the full Pest suite
php artisan test --compact
```

Integration tests run against a real MySQL server when a test account is configured. Otherwise they are skipped.

1. Create an account that can only touch `dbnova_test` and `dbnova_test_*` databases.
2. Set these in `.env`, or pass them when you run the tests:

```bash
DBNOVA_TEST_DB_USERNAME=dbnova_test DBNOVA_TEST_DB_PASSWORD=… \
DBNOVA_MYSQL_BIN=/usr/local/mysql/bin/mysql DBNOVA_MYSQLDUMP_BIN=/usr/local/mysql/bin/mysqldump \
php artisan test --compact
```

To generate stress data, use `php artisan dbnova:stress:seed-table` (a 10M-row table) or `php artisan dbnova:stress:make-dump --size=2G`.

## Roadmap

| Phase | Scope                                                                 | Status  |
| ----- | --------------------------------------------------------------------- | ------- |
| 0–5   | Architecture, foundation, explorer, table browser, schema, SQL editor | done    |
| 6     | Large import engine                                                   | done    |
| 7     | Export engine: SQL dumps and CSV                                      | done    |
| 8     | Monitoring: process list, variables, charts, table maintenance        | review  |
| 9     | Hardening and stress testing                                          | planned |
| 10    | Release, Docker Compose, install guide                                | planned |

The phase plans live in [`.claude/phases/`](.claude/phases/README.md). The architecture docs and decision records are in [`.claude/docs/`](.claude/docs/).

## Security

DBNova is effectively a superuser console for your MySQL server, so:

- Don't expose it publicly without HTTPS.
- For untrusted dumps, import with an account that is limited to the target database.

The threat model and the safeguards are documented in [`.claude/docs/SECURITY.md`](.claude/docs/SECURITY.md).

## License

MIT.

# DBNova

[![CI](https://github.com/vivek-mistry/dbnova/actions/workflows/ci.yml/badge.svg)](https://github.com/vivek-mistry/dbnova/actions/workflows/ci.yml) [![Release](https://img.shields.io/github/v/release/vivek-mistry/dbnova)](https://github.com/vivek-mistry/dbnova/releases)

A self-hosted MySQL manager: a faster, more trustworthy alternative to phpMyAdmin.

DBNova manages **one MySQL server** running on the same machine or VPS. You sign in with a MySQL account, and that account's grants decide what you can do. It's built for everyday browsing and for importing and exporting production-sized databases (10 GB and more) without running out of memory.

> **Status:** version 1.0.0. See the [changelog](CHANGELOG.md) and the [Roadmap](#roadmap).

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
    - **CSV:** one table, or the full result of a SQL editor query, to `.csv` or `.csv.gz`. NULL stays distinguishable from empty text, query exports are read-only, and an optional "safe for spreadsheets" mode neutralises formulas.
    - Exports run in a background job, with progress and cancel, and are downloaded through short-lived signed links.
- **Monitoring and maintenance.**
    - A live process list with Stop query / Kill connection, plus server variables and status counters.
    - Dashboard charts for queries per second, connections and buffer pool use.
    - OPTIMIZE / ANALYZE / CHECK / REPAIR on one or many tables, and a data vs index size breakdown.
- **Per-user preferences.** Rows per page, the SQL row limit and the query timeout, within maximums set in `.env`.
- **Safety.** A read-only mode enforced in the data layer, typed confirmations for destructive actions, and a filterable audit log that never stores row values.
- **Hardened.**
    - Passwords never reach logs or stack traces.
    - Sign-in must match the exact MySQL account, and password guessing is limited.
    - Every write action has its own rate limit.
    - Hostile database and table names are fuzz-tested against a real server.
    - Recovery is drilled: a killed worker, a stopped MySQL server, a full disk and a dropped upload connection all end cleanly.

### Measured on a laptop (MySQL 8.0.39)

| Operation                                    | Result                                        |
| -------------------------------------------- | --------------------------------------------- |
| Import 2 GB `.sql` (20.1M rows)              | 117 s, worker memory flat (+6 MB)             |
| Export 10M rows / 3.7 GB of SQL to `.sql.gz` | 26 s, worker memory flat (+4 MB), 261 MB file |
| Export a 10M-row table to `.csv`             | 23 s, worker memory flat (+2 MB)              |
| Export → re-import round trip                | identical row counts and table checksums      |
| Import 2 GB of SQL as `.sql.gz` (294 MB)     | 180 s, 57 MB peak worker process              |
| Browse a 10M-row table, any page (keyset)    | 3–8 ms per page, first or last                |
| Scroll a 120-column grid                     | 60 fps (rows and columns virtualized)         |

Measured on an Apple M2 (8 cores, 16 GB). The details and the recovery drill results are in [`.claude/docs/PERFORMANCE.md`](.claude/docs/PERFORMANCE.md).

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

Point DBNova at your server and client binaries in `.env` (the full list is under [Configuration](#configuration)):

```dotenv
APP_NAME=DBNova
DBNOVA_DB_HOST=127.0.0.1
DBNOVA_DB_PORT=3306
DBNOVA_MYSQL_BIN=/usr/bin/mysql            # full paths: `command -v mysql mysqldump`
DBNOVA_MYSQLDUMP_BIN=/usr/bin/mysqldump
```

`.env` only says **where** the server is. It never holds a MySQL username or password.

Check the setup, then run the app with a worker and the scheduler:

```bash
php artisan dbnova:install                                   # checks PHP, binaries, MySQL, storage and the worker
composer run dev                                             # app + Vite during development
php artisan queue:work --queue=imports,exports,default       # imports and exports run here
php artisan schedule:work                                    # recovery and cleanup (cron: schedule:run every minute)
```

Open the app and sign in with a MySQL account. A dedicated MySQL user is recommended over `root` (see below).

Production setup (nginx, supervisor, PHP limits) is described in [`.claude/docs/INSTALL.md`](.claude/docs/INSTALL.md).

## Configuration

### Recommended setup checklist

1. **Binary paths.** Set `DBNOVA_MYSQL_BIN` and `DBNOVA_MYSQLDUMP_BIN` to real files, not shell aliases. Find them with `command -v mysql mysqldump`. Common paths:

    | Install                  | Path                                               |
    | ------------------------ | -------------------------------------------------- |
    | Linux packages           | `/usr/bin/mysql`                                   |
    | macOS (Oracle installer) | `/usr/local/mysql/bin/mysql`                       |
    | Homebrew                 | `/opt/homebrew/bin/mysql`                          |
    | MAMP                     | `/Applications/MAMP/Library/bin/mysql80/bin/mysql` |

    The System page shows the version each binary reports.

2. **A dedicated MySQL account** for day-to-day work:

    ```sql
    CREATE USER 'dbnova'@'localhost' IDENTIFIED BY 'a-long-random-password';
    GRANT ALL PRIVILEGES ON *.* TO 'dbnova'@'localhost';    -- full manager
    -- read-only viewer instead: GRANT SELECT, SHOW VIEW, PROCESS ON *.* TO …; and DBNOVA_READ_ONLY=true
    ```

    Accounts with the global `CREATE USER` privilege (such as root) see every user's entries in the audit log. Other accounts see only their own.

3. **Run a queue worker and the scheduler.** Imports and exports run in the worker. Recovery and cleanup run on the scheduler. Without them, imports and exports stay "queued".
4. **Leave free disk space:** about 2× your largest import, plus your export files. DBNova refuses work that would leave less than `DBNOVA_MIN_FREE_DISK_BYTES` free.
5. **Serve it over HTTPS** and keep it off the public internet where you can (VPN, IP allow-list).
6. **Run `php artisan dbnova:install`** to check PHP extensions, the binaries, the MySQL connection, storage and the worker.

### Settings (`.env`)

| Setting                                                   | Default                  | What it does                                                                                  |
| --------------------------------------------------------- | ------------------------ | --------------------------------------------------------------------------------------------- |
| `DBNOVA_DB_HOST` / `DBNOVA_DB_PORT` / `DBNOVA_DB_SOCKET`  | `127.0.0.1` / `3306` / – | Where the managed MySQL server is. A socket path takes precedence over TCP.                   |
| `DBNOVA_MYSQL_BIN` / `DBNOVA_MYSQLDUMP_BIN`               | `/usr/bin/…`             | Full paths to the client tools. Required for imports and exports.                             |
| `DBNOVA_READ_ONLY`                                        | `false`                  | Refuse every change to the server. Browsing, `SELECT` and exports still work.                 |
| `DBNOVA_PAGE_SIZE` / `DBNOVA_MAX_PAGE_SIZE`               | `100` / `1000`           | Rows per page in the table browser: the default, and the most a user may choose.              |
| `DBNOVA_QUERY_ROW_CAP` / `DBNOVA_MAX_QUERY_ROW_CAP`       | `1000` / `10000`         | Rows the SQL editor shows. Use the CSV export for full results.                               |
| `DBNOVA_QUERY_TIMEOUT_MS` / `DBNOVA_MAX_QUERY_TIMEOUT_MS` | `30000` / `300000`       | MySQL stops a `SELECT` after this long (`max_execution_time`).                                |
| `DBNOVA_WORK_DIR`                                         | `storage/app/dbnova`     | Where uploads, export files and temp files go. Point it at a volume with room for your dumps. |
| `DBNOVA_MAX_UPLOAD_BYTES`                                 | 100 GB                   | Largest import file.                                                                          |
| `DBNOVA_MIN_FREE_DISK_BYTES`                              | 5 GB                     | Free disk that imports and exports must leave.                                                |
| `DBNOVA_EXPORT_FILE_HOURS` / `DBNOVA_EXPORT_LINK_MINUTES` | `24` / `60`              | How long export files are kept, and how long a download link works.                           |
| `DBNOVA_MAX_KEPT_EXPORTS`                                 | `10`                     | Finished export files one user may keep at once (`0` = no limit).                             |
| `DBNOVA_TEST_DB_USERNAME` / `…_PASSWORD`                  | –                        | Optional test account for the integration tests.                                              |

Each user can lower or raise the page size, row cap and query timeout for their own account under **Settings → Preferences**, up to the maximums above. Imports and exports always use the `.env` values. All settings, with comments, are in [`.env.example`](.env.example) and [`config/dbnova.php`](config/dbnova.php).

### CSV exports and spreadsheets

CSV files are exact copies of the data by default. If the data comes from untrusted users and someone will open the file by double-clicking it, tick **Safe for spreadsheets**: text starting with `=`, `+`, `-` or `@` gets a leading `'`, so Excel, Numbers and Google Sheets don't run it as a formula. Numbers are never changed.

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

Security tests (`tests/Feature/Security`) cover password leaks in stack traces, per-route rate limits and identifier fuzzing (a canary database must survive hostile names). The disk-full recovery drill is opt-in, because it needs a small throwaway volume:

```bash
diskutil erasevolume APFS dbnovadrill $(hdiutil attach -nomount ram://131072)   # macOS: a 64 MB RAM disk
DBNOVA_DRILL_SMALL_VOLUME=/Volumes/dbnovadrill php artisan test tests/Feature/Drills
```

## Roadmap

| Phase | Scope                                                                 | Status  |
| ----- | --------------------------------------------------------------------- | ------- |
| 0–5   | Architecture, foundation, explorer, table browser, schema, SQL editor | done    |
| 6     | Large import engine                                                   | done    |
| 7     | Export engine: SQL dumps and CSV                                      | done    |
| 8     | Monitoring: process list, variables, charts, table maintenance        | done    |
| 9     | Hardening and stress testing                                          | done    |
| 10    | Release and install guide                                             | planned |

The phase plans live in [`.claude/phases/`](.claude/phases/README.md). The architecture docs and decision records are in [`.claude/docs/`](.claude/docs/).

## Security

DBNova is effectively a superuser console for your MySQL server, so:

- Don't expose it publicly without HTTPS.
- For untrusted dumps, import with an account that is limited to the target database.
- Remove anonymous MySQL accounts (`mysql_secure_installation`). DBNova refuses sign-ins that MySQL matches to a different account, but the server shouldn't have them anyway.
- In production, set `zend.exception_ignore_args = On` in php.ini.

The threat model and the safeguards are documented in [`.claude/docs/SECURITY.md`](.claude/docs/SECURITY.md). To report a vulnerability privately, see [SECURITY.md](SECURITY.md).

## Contributing

`main` is protected. Changes arrive as pull requests that must pass CI (code quality and the MySQL test suite) and be approved by the maintainer before they can merge.

## License

MIT.

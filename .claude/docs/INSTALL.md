# Installation & Deployment (draft — finished in Phase 10)

## System requirements

| Component          | Requirement                                                                                                                                                     |
| ------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| OS                 | Linux (Ubuntu 22.04+/Debian 12) or macOS for development                                                                                                        |
| PHP                | ≥ 8.3 (CLI and FPM)                                                                                                                                             |
| PHP extensions     | `pdo_sqlite`, `pdo_mysql`, `mbstring`, `openssl`, `zip`, `zlib`, `fileinfo`, `ctype`, `tokenizer`, `xml`, `pcntl` and `posix` (for worker signals/cancellation) |
| MySQL server       | 8.0+ (5.7 best-effort), local                                                                                                                                   |
| MySQL client tools | `mysql` and `mysqldump` with a version matching the server                                                                                                      |
| Node               | 20+ (build only)                                                                                                                                                |
| Queue worker       | `php artisan queue:work` managed by supervisor or systemd                                                                                                       |
| Scheduler          | cron `* * * * * php artisan schedule:run`                                                                                                                       |
| Redis              | Optional (`QUEUE_CONNECTION=redis`, needs phpredis or predis)                                                                                                   |
| Disk               | Free space ≥ 2× the largest import (uploads + optional zip extraction)                                                                                          |

## MySQL user (recommended)

```sql
CREATE USER 'dbnova'@'localhost' IDENTIFIED BY '…';
-- Full manager (create/drop databases, import, export, process list, kill):
GRANT ALL PRIVILEGES ON *.* TO 'dbnova'@'localhost';
```

`ALL PRIVILEGES` already includes PROCESS, SHOW VIEW, EVENT and TRIGGER, which mysqldump `--routines --triggers --events` needs. `WITH GRANT OPTION` is not required.
For a read-only setup, grant `SELECT, SHOW VIEW, PROCESS` only and set `DBNOVA_READ_ONLY=true`.

## Environment (`.env`)

The full annotated list is in `.env.example`, and the README's "Configuration" section has a table. The minimum is:

```
APP_NAME=DBNova
DB_CONNECTION=sqlite              # internal app DB — NOT the managed server
QUEUE_CONNECTION=database

DBNOVA_DB_HOST=127.0.0.1
DBNOVA_DB_PORT=3306
DBNOVA_DB_SOCKET=
DBNOVA_MYSQL_BIN=/usr/bin/mysql            # `command -v mysql`; never a shell alias
DBNOVA_MYSQLDUMP_BIN=/usr/bin/mysqldump
DBNOVA_READ_ONLY=false
```

The user-adjustable limits (`DBNOVA_PAGE_SIZE`, `DBNOVA_QUERY_ROW_CAP` and `DBNOVA_QUERY_TIMEOUT_MS`) each have a `DBNOVA_MAX_*` ceiling. `DBNOVA_MAX_KEPT_EXPORTS` caps the finished export files one user may keep.

## Install steps

```
git clone … dbnova && cd dbnova
composer install --no-dev --optimize-autoloader
cp .env.example .env && php artisan key:generate
touch database/database.sqlite && php artisan migrate --force
npm ci && npm run build
php artisan dbnova:install      # verifies PHP, binaries, MySQL reachability, storage, worker (exit 1 on failure)
# Then open DBNova and sign in with a MySQL account (e.g. root or a dedicated user).
```

## Web server

- nginx + PHP-FPM, with the document root at `public/`.
- `client_max_body_size 32m;` (only needs to exceed one upload chunk).
- PHP: `upload_max_filesize=32M`, `post_max_size=32M`, `memory_limit=256M` (FPM). The worker can run with 128M.
- HTTPS is strongly recommended. DBNova should not be exposed publicly without TLS. MySQL passwords are submitted through the login form.

## Queue worker (supervisor example)

```
[program:dbnova-worker]
command=php /var/www/dbnova/artisan queue:work --queue=imports,exports,default --timeout=0 --tries=1
autorestart=true
stopwaitsecs=60
user=www-data
```

- `retry_after` for the `database` connection defaults to 90000 s (25 h, `DB_QUEUE_RETRY_AFTER`), above the import jobs' 24 h timeout, so a running import is never picked up twice. Don't lower it.
- Give the worker a long stop grace period (supervisor `stopwaitsecs`, systemd `TimeoutStopSec`), and use `php artisan queue:restart` for deploys. A worker killed mid-import leaves its `mysql` client running until the current statement and pipe buffer are done; the import is then marked `interrupted`.
- The worker needs `pcntl` and `posix` (job timeouts, stopping the `mysql` client on cancel), and `DBNOVA_MYSQL_BIN` must point at a real `mysql` client binary.
- One worker runs imports one at a time; a second import waits as "queued". While an import runs, the health page counts the import's own heartbeat as "worker alive".
- The scheduler must run (`schedule:run` every minute): `dbnova:recover-jobs` marks imports whose worker died as `interrupted` within about 2 minutes, and `dbnova:prune-temp` deletes expired uploads hourly (`DBNOVA_FAILED_IMPORT_HOURS`, `DBNOVA_INCOMPLETE_UPLOAD_HOURS`).
- For untrusted dumps, sign in with a MySQL account that only has rights on the target database: DBNova's one-database check is a best-effort guard, grants are the real boundary (SECURITY.md).

## Upgrading

`git pull && composer install --no-dev && php artisan migrate --force && npm ci && npm run build && php artisan queue:restart`

## Backups

Back up `database/database.sqlite` (users, history, audit log) and `.env`. Managed databases are backed up with DBNova's export or your own tooling.

## Docker Compose (Phase 10)

Services: `app` (php-fpm + nginx), `worker`, `scheduler`, plus the MySQL client binaries in the image. MySQL itself is the host's server, reached through a socket mount or `host.docker.internal`.

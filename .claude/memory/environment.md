# Local Environment (developer machine, macOS)

First checked on 2026-10-30, then confirmed with `SystemCheckService` in Phase 1.

- **PHP CLI:** 8.3.36 with every required and worker extension (pdo_mysql, pdo_sqlite, zip, zlib, pcntl, posix…). AGENTS.md (Boost) says "PHP 8.5". Treat `^8.3` from composer.json as the real minimum.
- **`mysql` shell alias is broken:** it points at `/Applications/MAMP/Library/bin/mysql`, which doesn't exist. Symfony Process can't see aliases anyway.
- **Working client binaries:** `/usr/local/mysql/bin/mysql` and `/usr/local/mysql/bin/mysqldump`, both 8.0.39 (versions match). Set `DBNOVA_MYSQL_BIN` / `DBNOVA_MYSQLDUMP_BIN` to these in `.env`.
- **MySQL server:** reachable on 127.0.0.1:3306 (MySQL 8.0.39). The user signs in to DBNova with their MySQL `root` account (the password is never stored in files or memory). The user's `.env` is off-limits to Claude (settings deny).
- **Test account:** MySQL user `dbnova_test` / password `test` (created 2026-10-30, at the user's request), limited to `dbnova_test` + `dbnova_test_*`. `dbnova_test_stress.orders` holds 10M benchmark rows. Run real-MySQL tests with `DBNOVA_TEST_DB_USERNAME=dbnova_test DBNOVA_TEST_DB_PASSWORD=test php artisan test --compact`.
- **Redis:** `/opt/homebrew/bin/redis-server` is installed but not required (the queue uses the database driver).
- **App DB:** SQLite at `database/database.sqlite`, with WAL mode on since Phase 1. `QUEUE_CONNECTION=database`, `CACHE_STORE=database`, `SESSION_DRIVER=database`.
- **Free disk:** about 43 GB, which is enough for a 10 GB import (needs ≥ 2× the dump size).
- **Boost:** configured for the Cursor agent only, with skills in `.cursor/skills/` (gitignored). `AGENTS.md` is generated and gitignored. The `search-docs` MCP tool isn't available to Claude here, so read the `.cursor/skills/*` files instead.

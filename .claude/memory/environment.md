# Local Environment (developer machine, macOS)

Checked on 2026-10-30. Re-check with the System Health page once it exists (Phase 1).

- **PHP CLI:** 8.3.x. AGENTS.md (Boost) says "PHP 8.5". Treat `^8.3` from composer.json as the real minimum.
- **`mysql` client:** a zsh **alias** to `/Applications/MAMP/Library/bin/mysql`. Symfony Process **cannot** see shell aliases, so set `DBNOVA_MYSQL_BIN` explicitly.
- **`mysqldump`:** `/usr/local/mysql/bin/mysqldump`, which comes from a different install than the `mysql` client. It may not match the server version, so the health check must show both versions.
- **Redis:** `/opt/homebrew/bin/redis-server` is installed but not required (the queue uses the database driver).
- **App DB:** SQLite at `database/database.sqlite`. `QUEUE_CONNECTION=database`, `CACHE_STORE=database`, `SESSION_DRIVER=database`.
- **Boost:** configured for the Cursor agent only, with skills in `.cursor/skills/` (gitignored). `AGENTS.md` is generated and gitignored.

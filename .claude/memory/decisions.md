# Decision Log

Newest entries go at the bottom. Use the format `YYYY-MM-DD — Decision — Why — ADR`.

- 2026-10-30 — The product is named **DBNova**. — The user chose it. — —
- 2026-10-30 — V1 is self-hosted, local MySQL only, with one destination database per import. — Finalized scope. — —
- 2026-10-30 — Hybrid access: raw PDO for interactive work, `mysql`/`mysqldump` CLI for import/export. — CLI batch output loses types and can't tell NULL from 'NULL', and a process per request is slow. The CLI streams huge files best. — ADR 0001
- 2026-10-30 — SQLite stores app metadata. — Keeps it independent of the managed server, with zero extra services. — ADR 0002
- 2026-10-30 — The queue uses the database driver (SQLite) by default, with Redis optional. — No new dependency or service, and the self-hosted install is simpler. — ADR 0003
- 2026-10-30 — Single admin, registration disabled, admin created with `dbnova:install`. — A DB tool must be locked down by default. — ADR 0004
- 2026-10-30 — Import progress uses polling first, and Reverb comes later. — Simpler, and enough for a single operator. — ADR 0005
- 2026-10-30 — Imports are streamed through a token-aware scanner. Multi-database dumps are rejected, and there's no rollback claim. — Correctness and honesty. — ADR 0006
- 2026-10-30 — The project's Claude workspace lives in a committed `.claude/` (docs, phases, memory, skills, agents, commands). — The user asked for it, so every session shares context. — ADR 0007
- 2026-10-30 — Claude Code hooks enabled (Bash credential guard, Pint on PHP edits). — The user opted in. — ADR 0007
- 2026-10-30 — `/` redirects to `/dashboard` (Welcome and Register pages deleted). The route name `home` is kept because auth layouts link to it. — The user chose it. — ADR 0004
- 2026-10-30 — `dbnova:install` skips admin creation when a user already exists, so there's no `--force` and it can safely be re-run to repeat the system checks. It never writes `.env`, and prints hints instead. — Keeps the single-admin rule strict and avoids editing secrets files. — ADR 0004
- 2026-10-30 — Phase 1's MySQL reachability check uses a raw PDO connect inside `SystemCheckService`. Phase 2 moves it behind `MySQLConnection`. — Seed only. — ADR 0001
- 2026-10-30 — Queue liveness: the scheduler queues `RecordQueueHeartbeat` every minute, and the worker writes a cache timestamp. More than 180 s old counts as stale. — Proves both the scheduler and the worker are running. — ADR 0003
- 2026-10-30 — Phase 0 approved and committed. Work happens on phase branches (`phase/NN-name`) because main is the default branch. — Commit policy. — —
- 2026-10-30 — **Login is a MySQL account** (phpMyAdmin style). It supersedes the single-admin model. Profile, Security, 2FA, passkeys, password reset, email verification and remember-me are removed, and their tests deleted (user approved). The host is fixed in `.env`, with no host field. — The user asked for it. — ADR 0008
- 2026-10-30 — `dbnova:install` now only runs the system checks and exits 1 on failure. — No admin to create. — ADR 0008
- 2026-10-30 — Database rename = create + one atomic `RENAME TABLE` for all tables + drop the old one. It's refused when the database has views, routines, events or triggers. If the move fails, the new database is dropped. — The user chose it. Honest and safe. — —
- 2026-10-30 — Integration tests use a dedicated MySQL user limited to `dbnova\_test\_%`, with credentials in `.env` (`DBNOVA_TEST_DB_*`). CI uses a throwaway `mysql:8.0` container. — The user chose it. — —
- 2026-10-30 — Dropping a database asks for the typed name + MySQL password in one dialog (not Fortify's redirecting `password.confirm`, which can't replay a DELETE). — UX + safety. — ADR 0008
- 2026-10-30 — Adapter contracts grow per phase instead of declaring unimplemented methods up front. — There's no dead code, and each phase owns its contract. — ADR 0001
- 2026-10-30 — Approved and installed `@tanstack/vue-table@^9.3.1` + `@tanstack/vue-virtual@^3.13.50`. Table v9 API (`useTable`, `tableFeatures`): use the guides in `node_modules/@tanstack/vue-table/skills/`. Phase 3 is split into 3a (browse) and 3b (edit). The stress table goes in `dbnova_test_stress`. — The user chose it. — —
- 2026-10-30 — Row edits are key-only (PK or NOT NULL unique key): UPDATE … WHERE key LIMIT 1, and DELETE by key in one transaction (≤ 1,000 per request). Keyless tables, views, binary and generated columns aren't editable in the grid. — Never touch the wrong row. — —
- 2026-10-30 — Audit entries for row changes store column names and counts, never values. — Table data may be sensitive. — —
- 2026-10-30 — Created MySQL user `dbnova_test` (password `test`, grants only on `dbnova_test` and `dbnova\_test\_%`) and database `dbnova_test` at the user's request, for real-MySQL tests. Kept for future phases. — The user asked for it. — —

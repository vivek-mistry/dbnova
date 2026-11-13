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
- 2026-10-30 — Schema changes run online first (INSTANT → INPLACE, LOCK=NONE → COPY). A COPY on a table over `dbnova.limits.locking_ddl_confirm_rows` (1M) needs explicit confirmation (HTTP 409 → "Run anyway"). DDL runs in the request, not a queue. — The user chose it. — —
- 2026-10-30 — Schema editing uses per-action dialogs (Structure tab) plus a Create-table form, every one through the shared preview → run dialog. — The user chose it. — —
- 2026-10-30 — Drop column, truncate and drop table need the typed table name + MySQL password at apply time (not at preview). — Same pattern as drop database. — ADR 0008
- 2026-11-13 — Approved and installed `codemirror@^6.0.2` + `@codemirror/lang-sql@^6.10.0`. The SQL editor stops at the first error (no wrapping transaction) and confirms destructive statements (DROP, TRUNCATE, UPDATE/DELETE without WHERE) before running. — The user chose it. — —
- 2026-11-13 — Phase 6 is split into 6a (chunked upload, source reader, preflight) and 6b (import job, cancel/recovery, wizard, stress). The local stress test uses a 2 GB dump and extrapolates to 10 GB (about 32 GB of disk free). The speed prelude is off by default. A dump made for one database (`USE shop`) imports into the chosen target with a warning. — The user chose it. — ADR 0006
- 2026-11-13 — Uploads write each chunk at its byte offset into one file, plus a marker file per received chunk. There's no assembly step and no second copy on disk. `.gz` is inflated incrementally (`inflate_add`, multi-member aware) and `.zip` is read with `ZipArchive::getStream()`, so nothing is extracted to disk. — 1× disk instead of 2×, and no long copy inside an HTTP request. — ADR 0006
- 2026-11-13 — The queue `retry_after` is raised to 25 h on the `database` connection, and preflight/import jobs have `tries = 1` and a 24 h timeout. A single worker runs `--queue=imports,exports,default`. — The database driver re-runs any job that takes longer than `retry_after`, which would start a duplicate import. — ADR 0003
- 2026-11-13 — The `mysql` import process always gets `--binary-mode` (turns off client commands such as `\!` shell and `source` in piped input) and `--local-infile=0` (a dump can't read files on the DBNova host). — A dump file is untrusted input. — SECURITY.md
- 2026-11-13 — `SqlStreamScanner` was rewritten for throughput. It copies raw slices and no longer builds a blanked copy of every statement. `SqlStatement` derives keyword and code on demand. — The Phase 5 version ran at 16 MB/s (11 min per pass for 10 GB). — —

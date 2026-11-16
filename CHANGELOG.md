# Changelog

All notable changes to DBNova are documented here. The project follows [Semantic Versioning](https://semver.org/).

## [1.0.0] - 2026-11-16

The first release: a self-hosted MySQL manager for one server, built with Laravel 13, Inertia v3, Vue 3 and TypeScript.

### Added

- **Sign in with a MySQL account.** There are no app accounts or app passwords. Credentials live only in the encrypted session.
- **Databases.** List, create, rename and drop them, with sizes, table counts and collations. Dropping needs the typed name and the MySQL password.
- **Table browser:**
    - keyset paging that stays fast on 10M-row tables (3–8 ms per page);
    - a virtualized grid that tells NULL from empty text;
    - inline edit, insert and delete on tables with a primary or unique key.
- **Schema management.** Create and alter tables, columns, indexes and foreign keys, with a SQL preview before anything runs. Online ALTERs are used where possible.
- **SQL editor:**
    - CodeMirror with schema autocomplete;
    - multi-statement runs, a row cap and a timeout;
    - cancel, history and saved queries;
    - ⌘/Ctrl + Enter to run.
- **Large imports** (`.sql`, `.sql.gz`, `.zip`):
    - resumable chunked uploads;
    - a streaming preflight check;
    - a queued import with honest progress, cancel and crash recovery;
    - 2 GB in about 2–3 minutes with flat memory.
- **Exports.** SQL dumps (`.sql`, `.sql.gz`) and CSV (a table or a query result) in background jobs, with short-lived signed download links. CSV has an optional "safe for spreadsheets" mode.
- **Monitoring:**
    - a live process list with Stop query / Kill connection;
    - server variables and status counters;
    - live dashboard charts.
- **Maintenance.** OPTIMIZE, ANALYZE, CHECK and REPAIR on one or many tables, with a data vs index size breakdown.
- **Audit log viewer** with filters. Row values are never stored, and errors are redacted.
- **Per-user preferences** (rows per page, SQL row limit, query timeout), within maximums set in `.env`.
- **Read-only mode**, enforced in the data layer.

### Security

- Passwords never appear in logs or stack traces (`#[\SensitiveParameter]`).
- Option files for the `mysql`/`mysqldump` CLI refuse line-break injection.
- Sign-in must match the exact MySQL account, so anonymous accounts are refused.
- Failed password checks are limited per username, everywhere a password is re-entered.
- One SQL statement per connection call (multi-statements are off).
- Every write route has its own rate limit.
- Identifier validation was fuzz-tested against a real MySQL server.
- Recovery drills: a killed worker, a stopped MySQL server, a full disk and a dropped upload connection all end in a clear state.
- Dependencies are audited in CI (`composer audit`, `npm audit`), with CodeQL scanning and Dependabot updates.

[1.0.0]: https://github.com/vivek-mistry/dbnova/releases/tag/v1.0.0

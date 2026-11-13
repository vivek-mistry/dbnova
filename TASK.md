# DBNova — Current Task

**Current phase:** Phase 5 — SQL editor (starting)
**Phase file:** [.claude/phases/phase-05-sql-editor.md](.claude/phases/phase-05-sql-editor.md)
**Status:** Starting (waiting on dependency approval)
**Branch:** `phase/00-architecture` (all phases so far)

## Done

- Phase 0 — Architecture, documentation & `.claude/` workspace
- Phase 1 — Foundation, MySQL account login (ADR 0008), system health
- Phase 2 — Adapter layer, database explorer, audit log
- Phase 3 — Table browser (keyset paging, 10M rows verified), record editing, command palette
- Phase 4 — Schema management (online-first ALTERs, SQL preview, create/alter/drop)

## Test environment (local MySQL)

- MySQL user `dbnova_test` / `test`, limited to `dbnova_test` and `dbnova_test_*` databases
- `dbnova_test_stress.orders`: 10M rows for benchmarks (about 2 GB, can be dropped)
- Run real-MySQL tests: `DBNOVA_TEST_DB_USERNAME=dbnova_test DBNOVA_TEST_DB_PASSWORD=test php artisan test --compact` (or put both in `.env`)

## Blocked / waiting on user

- Approve the CodeMirror dependencies for Phase 5

- Optional: add `DBNOVA_TEST_DB_USERNAME` / `DBNOVA_TEST_DB_PASSWORD` and `DBNOVA_MYSQL_BIN` / `DBNOVA_MYSQLDUMP_BIN` to `.env`

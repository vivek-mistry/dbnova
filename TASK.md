# DBNova — Current Task

**Current phase:** Phase 6 — Large import engine (not started)
**Phase file:** [.claude/phases/phase-06-import.md](.claude/phases/phase-06-import.md)
**Status:** Not started
**Branch:** `phase/00-architecture` (all phases so far)

## Now

- Start Phase 6: confirm the import plan and decisions

## Done

- Phase 0 — Architecture, documentation & `.claude/` workspace
- Phase 1 — Foundation, MySQL account login (ADR 0008), system health
- Phase 2 — Adapter layer, database explorer, audit log
- Phase 3 — Table browser (keyset paging, 10M rows verified), record editing, command palette
- Phase 4 — Schema management (online-first ALTERs, SQL preview, create/alter/drop)
- Phase 5 — SQL editor (CodeMirror, multi-statement runs, row cap, cancel, history/saved queries)

## Test environment (local MySQL)

- MySQL user `dbnova_test` / `test`, limited to `dbnova_test` and `dbnova_test_*` databases
- `dbnova_test_stress.orders`: 10M rows for benchmarks (about 2 GB, can be dropped)
- Run real-MySQL tests: `DBNOVA_TEST_DB_USERNAME=dbnova_test DBNOVA_TEST_DB_PASSWORD=test php artisan test --compact` (or put both in `.env`)

## Blocked / waiting on user

- Optional: add `DBNOVA_TEST_DB_USERNAME` / `DBNOVA_TEST_DB_PASSWORD` and `DBNOVA_MYSQL_BIN` / `DBNOVA_MYSQLDUMP_BIN` to `.env`

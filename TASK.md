# DBNova — Current Task

**Current phase:** Phase 7 — Export engine (not started; awaiting `/phase-start 7` approvals)
**Phase file:** [.claude/phases/phase-07-export.md](.claude/phases/phase-07-export.md)
**Status:** Not started
**Branch:** `phase/00-architecture` (all phases so far)

## Now

- Phase 7 kickoff: confirm open questions, then build the export engine
- Still pending from Phase 6: browser check of imports (needs `php artisan queue:work --queue=imports,exports,default`, `php artisan schedule:work`, and `DBNOVA_MYSQL_BIN=/usr/local/mysql/bin/mysql` in `.env`)

## Done

- Phase 0 — Architecture, documentation & `.claude/` workspace
- Phase 1 — Foundation, MySQL account login (ADR 0008), system health
- Phase 2 — Adapter layer, database explorer, audit log
- Phase 3 — Table browser (keyset paging, 10M rows verified), record editing, command palette
- Phase 4 — Schema management (online-first ALTERs, SQL preview, create/alter/drop)
- Phase 5 — SQL editor (CodeMirror, multi-statement runs, row cap, cancel, history/saved queries)
- Phase 6 — Large import engine (chunked resumable uploads, streaming preflight, queued import with cancel/recovery; 2 GB in 117 s, flat memory)

## Test environment (local MySQL)

- MySQL user `dbnova_test` / `test`, limited to `dbnova_test` and `dbnova_test_*` databases
- `dbnova_test_stress` was dropped after the Phase 6 stress run
- Run real-MySQL tests: `DBNOVA_TEST_DB_USERNAME=dbnova_test DBNOVA_TEST_DB_PASSWORD=test php artisan test --compact` (or put both in `.env`)

## Blocked / waiting on user

- Add `DBNOVA_MYSQL_BIN=/usr/local/mysql/bin/mysql` (and `DBNOVA_MYSQLDUMP_BIN=/usr/local/mysql/bin/mysqldump` for Phase 7) to `.env`
- Optional: add `DBNOVA_TEST_DB_USERNAME` / `DBNOVA_TEST_DB_PASSWORD` to `.env`

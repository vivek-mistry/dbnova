# DBNova — Current Task

**Current phase:** Phase 9 — Hardening & stress testing
**Phase file:** [.claude/phases/phase-09-hardening.md](.claude/phases/phase-09-hardening.md)
**Status:** Starting
**Branch:** `phase/00-architecture` (all phases so far)

## Now

- Phase 9: start (see the phase file)

## Done

- Phase 0 — Architecture, documentation & `.claude/` workspace
- Phase 1 — Foundation, MySQL account login (ADR 0008), system health
- Phase 2 — Adapter layer, database explorer, audit log
- Phase 3 — Table browser (keyset paging, 10M rows verified), record editing, command palette
- Phase 4 — Schema management (online-first ALTERs, SQL preview, create/alter/drop)
- Phase 5 — SQL editor (CodeMirror, multi-statement runs, row cap, cancel, history/saved queries)
- Phase 6 — Large import engine (chunked resumable uploads, streaming preflight, queued import with cancel/recovery; 2 GB in 117 s, flat memory)
- Phase 7 — Export engine (SQL dumps and CSV; one statement per connection call after security review 7b)
- Phase 8 — Monitoring (process list with kill, variables/status, live charts, table maintenance, size breakdown, audit viewer, per-user preferences)

## Test environment (local MySQL)

- MySQL user `dbnova_test` / `test`, limited to `dbnova_test` and `dbnova_test_*` databases
- Stress databases are dropped after each run (`dbnova:stress:seed-table` recreates the 10M-row table)
- Run real-MySQL tests: `DBNOVA_TEST_DB_USERNAME=dbnova_test DBNOVA_TEST_DB_PASSWORD=test DBNOVA_MYSQL_BIN=/usr/local/mysql/bin/mysql DBNOVA_MYSQLDUMP_BIN=/usr/local/mysql/bin/mysqldump php artisan test --compact`

## Blocked / waiting on user

- Your local `.env`: set `DBNOVA_MYSQL_BIN=/usr/local/mysql/bin/mysql` and `DBNOVA_MYSQLDUMP_BIN=/usr/local/mysql/bin/mysqldump` (Claude may not edit `.env`)

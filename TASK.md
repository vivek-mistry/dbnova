# DBNova — Current Task

**Current phase:** Phase 7 — Export engine (7a committed; 7b next)
**Phase file:** [.claude/phases/phase-07-export.md](.claude/phases/phase-07-export.md)
**Status:** In progress (7b next)
**Branch:** `phase/00-architecture` (all phases so far)

## Now

- User review of 7a in the browser (needs `php artisan queue:work --queue=imports,exports,default` and `php artisan schedule:work`, plus `DBNOVA_MYSQL_BIN` / `DBNOVA_MYSQLDUMP_BIN` in `.env`):
  Import / Export → Export tab → database → all or chosen tables → format → Export → Download. Try: Cancel mid-way, Delete file, re-import the download on the Import tab
- Then 7b — CSV of tables and SQL editor results (always through the job)

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
- Stress databases are dropped after each run (`dbnova:stress:seed-table` recreates the 10M-row table)
- Run real-MySQL tests: `DBNOVA_TEST_DB_USERNAME=dbnova_test DBNOVA_TEST_DB_PASSWORD=test DBNOVA_MYSQL_BIN=/usr/local/mysql/bin/mysql DBNOVA_MYSQLDUMP_BIN=/usr/local/mysql/bin/mysqldump php artisan test --compact`

## Blocked / waiting on user

- Decide on LOW-2: a cap on completed exports kept per user (e.g. 10 files or N GB), or leave as is
- Add `DBNOVA_MYSQL_BIN=/usr/local/mysql/bin/mysql` and `DBNOVA_MYSQLDUMP_BIN=/usr/local/mysql/bin/mysqldump` to `.env`

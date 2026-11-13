# DBNova — Current Task

**Current phase:** Phase 6 — Large import engine (6a done, awaiting review; 6b next)
**Phase file:** [.claude/phases/phase-06-import.md](.claude/phases/phase-06-import.md)
**Status:** In progress
**Branch:** `phase/00-architecture` (all phases so far)

## Now

- User review of Phase 6a: Import / Export → choose a `.sql`/`.sql.gz`/`.zip` file and a database → "Upload and check". Try Pause/Resume, reload mid-upload and choose the same file again (it resumes), and a multi-database dump (it is rejected)
- A queue worker must be running for the check: `php artisan queue:work --queue=imports,exports,default`
- Then 6b: the import job itself, cancel/recovery, failure screen, 2 GB stress run

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

- Approve the Phase 6a commit

- Optional: add `DBNOVA_TEST_DB_USERNAME` / `DBNOVA_TEST_DB_PASSWORD` and `DBNOVA_MYSQL_BIN` / `DBNOVA_MYSQLDUMP_BIN` to `.env`

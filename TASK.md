# DBNova — Current Task

**Current phase:** Phase 3b — Record editing & command palette
**Phase file:** [.claude/phases/phase-03-table-browser.md](.claude/phases/phase-03-table-browser.md)
**Status:** In progress
**Branch:** `phase/00-architecture` (all phases so far)

## Now

- Build 3b: RecordController, inline edit, insert, delete, command palette

## Next

- Phase 4 — Schema management

## Done

- Phase 0 — Architecture, documentation & `.claude/` workspace
- Phase 1 — Foundation, MySQL account login (ADR 0008), system health
- Phase 2 — Adapter layer, database explorer, audit log
- Phase 3a — Read-only table browser (keyset paging, virtual grid, filters)

## Blocked / waiting on user

- Create the `dbnova_test` MySQL user and add `DBNOVA_TEST_DB_USERNAME` / `DBNOVA_TEST_DB_PASSWORD` to `.env` (SQL in `.claude/docs/TESTING.md`). That unlocks the integration tests and `php artisan dbnova:stress:seed-table` for the 10M-row benchmark
- Set `DBNOVA_MYSQL_BIN` / `DBNOVA_MYSQLDUMP_BIN` in `.env`

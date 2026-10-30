# DBNova — Current Task

**Current phase:** Phase 3 — Table browser & data grid (starting)
**Phase file:** [.claude/phases/phase-03-table-browser.md](.claude/phases/phase-03-table-browser.md)
**Status:** Starting
**Branch:** `phase/00-architecture` (all phases so far)

## Now

- `/phase-start 3`: confirm the dependencies and open questions

## Done

- Phase 0 — Architecture, documentation & `.claude/` workspace
- Phase 1 — Foundation, MySQL account login (ADR 0008), system health
- Phase 2 — Adapter layer, database explorer, audit log

## Blocked / waiting on user

- Create the `dbnova_test` MySQL user and add `DBNOVA_TEST_DB_USERNAME` / `DBNOVA_TEST_DB_PASSWORD` to `.env` (SQL in `.claude/docs/TESTING.md`)
- Set `DBNOVA_MYSQL_BIN` / `DBNOVA_MYSQLDUMP_BIN` in `.env`, if not done yet

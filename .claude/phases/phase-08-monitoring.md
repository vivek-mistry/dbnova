# Phase 8 — Monitoring & advanced operations

**Status:** not started
**Goal:** Visibility into the running server, plus maintenance operations.
**New dependencies (approval needed):** `apexcharts`, `vue3-apexcharts`.

## Tasks
- [ ] Process list (`SHOW FULL PROCESSLIST` / `performance_schema`) with auto-refresh and kill (confirm + audit)
- [ ] Server variables (searchable) and status counters
- [ ] Dashboard charts: queries/sec, connections, InnoDB buffer pool usage (polled deltas)
- [ ] Table maintenance: OPTIMIZE / ANALYZE / CHECK / REPAIR (MyISAM), single and bulk, with results shown
- [ ] Database/table size breakdown (data vs index) on the database page
- [ ] Audit log viewer (`pages/system/Audit.vue`) with filters
- [ ] User-facing settings page for DBNova preferences (page size, row cap, query timeout)

## Tests
- [ ] Feature: kill requires confirmation and is audited, and read-only blocks kill and maintenance
- [ ] Integration: the process list includes a running `SLEEP()`, and kill ends it

## Acceptance criteria
- The process list refreshes without full page reloads, and kill works.
- Maintenance operations report MySQL's result rows.

## Completion notes
_(filled by /phase-done)_

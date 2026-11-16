# Phase 8 — Monitoring & advanced operations

**Status:** done (8a 8535d4d, 8b aa0fe12)
**Goal:** Visibility into the running server, plus maintenance operations.
**New dependencies:** none. The user chose hand-built SVG charts over `apexcharts`.

**User decisions (2026-11-16):**

- **Charts:** no new dependency (small SVG line charts).
- **Split:** 8a is the live server (processes, variables/status, charts); 8b is operations (maintenance, size breakdown, audit viewer, settings).
- **Kill:** "Stop query" (KILL QUERY) is the default, and "Kill connection" (KILL) sits in a menu. Both need confirmation and are audited.
- **Settings:** per-user preferences in SQLite, capped by the `.env` values.

## Tasks

### 8a — live server

- [x] Process list (`SHOW FULL PROCESSLIST`) with 2 s auto-refresh (pauses when the tab is hidden), filter, idle toggle, "Stop query" / "Kill connection" (confirm + audit)
- [x] Server variables (searchable) and status counters (tabs on the Processes page)
- [x] Dashboard charts: queries/sec, connections, InnoDB buffer pool usage (polled deltas, hand-built SVG `LineChart`)

### 8b — operations

- [x] Table maintenance: OPTIMIZE / ANALYZE / CHECK / REPAIR (MyISAM), single and bulk, with results shown
- [x] Database/table size breakdown (data vs index) on the database page
- [x] Audit log viewer (`pages/system/Audit.vue`) with filters
- [x] User-facing settings page for DBNova preferences (page size, row cap, query timeout): per user, capped by `.env`

## Tests

- [x] Feature: kill requires confirmation and is audited, and read-only blocks kill
- [x] Feature: maintenance validation, audit and read-only; audit viewer visibility and filters; preferences validation and effect
- [x] Integration: the process list includes a running `SLEEP()`, and kill ends it
- [x] Integration: OPTIMIZE/ANALYZE/CHECK/REPAIR on InnoDB and MyISAM, base tables only, read-only allows CHECK only; data/index sizes; `hasGlobalPrivilege`

## Acceptance criteria

- The process list refreshes without full page reloads, and kill works.
- Maintenance operations report MySQL's result rows.

## Completion notes

### 8a — live server (2026-11-16, committed 8535d4d)

- **Adapter:** `ServerInspectorInterface` gained `processes()`, `kill(id, connection)`, `variables()`, `status()` and `metrics()`.
    - Kill runs `KILL QUERY n` or `KILL n` with an integer id. It refuses read-only mode in the adapter and refuses DBNova's own connection. An unknown thread (1094) returns false.
    - Metrics come from `SHOW GLOBAL STATUS`, so they work when `performance_schema` is off.
- **Processes page** (`processes/Index`):
    - Polls `processes.list` every 2 s and pauses when the tab is hidden. It has a filter and hides idle connections by default.
    - Your own DBNova connection is flagged.
    - "Stop query" is offered for `Query` and `Execute` (prepared statements); "Kill connection" is in a menu. Both open a confirm dialog that shows the SQL. The server requires `confirm: true` and audits `process.kill-query` or `process.kill` with the target's id, user, command and time.
- **Variables and Status:** tabs with a filter (`server.variables`, `server.status`).
- **Dashboard:** live charts poll `server.metrics` every 5 s and keep 5 minutes in the browser.
    - Queries/s, connected clients versus threads running (MySQL counts background threads such as the event scheduler as running, which the chart notes), and buffer pool in use.
    - The `LineChart` follows the dataviz rules: one y-axis from 0, 2 px lines, a crosshair tooltip, a legend with current values for 2 series, and an sr-only data table.
    - The palette was validated: dark `--chart-2` was lowered to `hsl(160 60% 40%)` to pass the lightness band.
- Checked with headless Chrome screenshots (dark and light), including the kill dialog on a real running `SLEEP(40)`.
- Tests: 623 passing against real MySQL. Integration tests find a CLI `SLEEP(30)`, stop its query (the client stays connected, exit 0) and kill its connection (the client exits non-zero). `composer ci:check` is green.

### 8b — operations (2026-11-16, committed aa0fe12)

- **Size breakdown:** `TableInfo` gained `dataBytes`, `indexBytes` and `freeBytes` (`DATA_FREE`).
    - The database page shows a storage bar (data vs indexes, 2 px gap, legend with values) and per-table Data / Indexes / Free / Total columns.
    - "Free" is labelled "allocated but unused", because InnoDB always reports a few MB per table and OPTIMIZE can't return all of it.
- **Maintenance:** `TableManagerInterface::maintain()` plus `MaintenanceController` (`POST databases/{db}/maintenance`).
    - Tick tables (views can't be selected), then use the Maintenance menu. A dialog explains the operation, runs it and shows MySQL's rows (status, note, error).
    - Write operations are audited with an error count, and the page reloads sizes after OPTIMIZE and ANALYZE.
    - `CHECK TABLE` runs through `PDO::query`, because it can't be prepared.
- **Audit log** (`system/Audit`, sidebar entry "Audit log"):
    - Filters for action, user, database and result, with 50 entries per page.
    - Your own entries only, unless the account holds global `CREATE USER`.
- **Preferences** (Settings → Preferences, now the default settings page):
    - Rows per page, SQL editor row limit, and query timeout (in seconds). Empty means the default.
    - Maximums come from `DBNOVA_MAX_PAGE_SIZE` (1000), `DBNOVA_MAX_QUERY_ROW_CAP` (10000) and `DBNOVA_MAX_QUERY_TIMEOUT_MS` (300000).
    - `ApplyUserPreferences` overrides the config for the request, so every existing reader picks the values up unchanged.
- **Checks:**
    - Headless Chrome screenshots, dark and light, on a seeded database (InnoDB + MyISAM + a view): OPTIMIZE of 3 tables shrank it from 22.1 MB to 16.8 MB and showed MySQL's "recreate + analyze" notes. The audit entry and the preferences page were also captured.
    - 653 tests pass against real MySQL, and `composer ci:check` is green.

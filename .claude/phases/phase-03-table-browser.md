# Phase 3 — Table browser & data grid

**Status:** not started
**Goal:** Fast browsing of very large tables with server-side pagination, a virtualized grid, and inline record editing.
**New dependencies (approval needed):** `@tanstack/vue-table`, `@tanstack/vue-virtual`.
**Read first:** `docs/PERFORMANCE.md`, `docs/DESIGN.md` (data grid), the `dbnova-data-grid` skill.

## Tasks
- [ ] `TableBrowserInterface` + `MySQLTableBrowser`: `page()` with keyset (PK/unique) or offset fallback, sort, filters (=, ≠, LIKE, IS NULL, >, <), approximate/exact count
- [ ] DTOs: `TableRef`, `PageRequest`, `Page` (rows, columns meta, cursor, hasMore, timing)
- [ ] Column metadata: types, nullable, key, default, extra (for editors and display)
- [ ] TEXT/BLOB truncation + a `GET` endpoint for the full value
- [ ] `TableController@show` (first page as Inertia props), plus a `TableRowsController` JSON endpoint for later pages
- [ ] `RecordController`: insert, update (by PK/unique key), delete (single + bulk). Rejected for tables without a key. Audited, and blocked in read-only mode
- [ ] `components/data-grid/`: `DataGrid.vue`, `DataGridCell.vue`, `DataGridHeader.vue`, `CellEditor.vue`, plus a `useDataGrid` composable
- [ ] `pages/tables/Browse.vue`, `pages/tables/Structure.vue` (read-only), and a table tabs layout
- [ ] Command palette (Cmd/Ctrl+K) to jump to a database or table
- [ ] Stress fixture: `dbnova:stress:seed-table` (10M rows), pulled forward from Phase 9

## Tests
- [ ] Unit: keyset/offset SQL builder (composite PKs, sort direction, NULL handling)
- [ ] Integration: pagination stays consistent across pages, and update/delete only touch the targeted row
- [ ] Feature: editing a keyless table is refused, read-only is enforced, audit is written

## Acceptance criteria
- A 10M-row table: first and deep pages each ≤ ~200 ms on suitable hardware, and PHP memory is flat (recorded in PERFORMANCE.md).
- Edits save without a page reload, and failed edits roll back in the UI with the MySQL message.
- NULL vs empty string vs BLOB are visually distinct.

## Completion notes
_(filled by /phase-done)_

# Phase 3 — Table browser & data grid

**Status:** in progress (3b) · 3a done
**Goal:** Fast browsing of very large tables with server-side pagination, a virtualized grid, and inline record editing.
**New dependencies (approved 2026-10-30):** `@tanstack/vue-table@^9.3.1`, `@tanstack/vue-virtual@^3.13.50`. Table v9 is a new major: read `node_modules/@tanstack/vue-table/skills/` before using it.
**Read first:** `docs/PERFORMANCE.md`, `docs/DESIGN.md` (data grid), the `dbnova-data-grid` skill.
**Delivery:** split at the user's request into **3a (browse)** and **3b (edit)**, each reviewed and committed separately.

## 3a — Browse (read-only)

- [x] `TableBrowserInterface` + `MySQLTableBrowser`: `columns()`, `indexes()`, `createStatement()`, `page()`, `count()`, `value()`
- [x] `MySQLPageQueryBuilder` (pure, unit-tested):
    - keyset on the PK or first NOT NULL unique key when unsorted or sorted by that key, and offset otherwise
    - filters (=, ≠, LIKE, NOT LIKE, IS NULL, IS NOT NULL, >, ≥, <, ≤)
    - fetches limit + 1 for `hasMore`
- [x] DTOs: `TableRef`, `ColumnInfo`, `IndexInfo`, `PageRequest`, `Filter` (+ `FilterOperator` enum), `Page`
- [x] Large values: text is previewed at 256 characters (truncation flagged), and binary columns are sent as their byte length only. A `value` endpoint returns the full text, or a hex preview for binary
- [x] `TableController@show` (Browse, first page as an Inertia prop) and `@structure`. `TableRowsController` JSON: rows, count, value
- [x] `components/data-grid/`: `DataGrid.vue` (TanStack Table v9 + Virtual, sticky header, resizable columns, sort on header click, filter bar, infinite scroll), `FilterBar.vue`, `DataGridCell.vue`, `CellValueDialog.vue`, `useDataGrid` composable
- [x] `pages/tables/Browse.vue`, `pages/tables/Structure.vue` (columns, indexes, CREATE statement), `components/table/TableHeader.vue` (tabs + breadcrumbs; renamed from TableTabs)
- [x] Sidebar tree tables and database page table names link to the browser
- [x] `MySQLConnection::useCredentials()` for CLI and jobs (needed by the stress command, and by Phases 6 and 7)
- [x] Stress fixture: `dbnova:stress:seed-table` (10M rows into `dbnova_test_stress`, run as the `dbnova_test` user). It refuses non-`dbnova_test_` targets. **Not run yet: waiting on the test user**

### Tests (3a)

- [x] Unit: page query builder (keyset/offset, composite keys, direction, filters, NULL operators, injection through column names)
- [x] Feature: controllers (404 for an unknown table, validation of sort/filter/limit, cursor round-trip, JSON shapes)
- [x] Integration: keyset pages are consistent and non-overlapping, descending pages, filters/counts, offset fallback for keyless tables, truncation/binary handling. **Skipped locally until the test user exists**
- [ ] Benchmark: first + deep page on 10M rows. ⏳ Waiting on the test user + stress seed. Measured on a real 1.2M-row table instead (PERFORMANCE.md)

## 3b — Edit

- [ ] `RecordController`: insert, update (by PK/unique key), delete (single + bulk). Rejected for tables without a key. Audited, and blocked in read-only mode
- [ ] Inline cell editing (`CellEditor.vue`) with optimistic update + rollback, insert row form, row selection + bulk delete
- [ ] Command palette (Cmd/Ctrl+K) to jump to a database or table

### Tests (3b)

- [ ] Feature: editing a keyless table is refused, read-only is enforced, audit is written
- [ ] Integration: update/delete only touch the targeted row

## Acceptance criteria

- A 10M-row table: first and deep pages each ≤ ~200 ms on suitable hardware, and PHP memory is flat (recorded in PERFORMANCE.md).
- Edits save without a page reload, and failed edits roll back in the UI with the MySQL message. (3b)
- NULL vs empty string vs BLOB are visually distinct.

## Completion notes

### 3a

- **Shipped:** read-only browsing as listed above. Tables open from the sidebar tree and the database page, with Browse and Structure tabs.
- **Real-data check** (root, local MySQL 8.0.39, `ecommerce.product_images`, 1.2M rows):
    - keyset page 1: 9.7 ms; page 51: 0.2 ms
    - a 1,000-row page: 1.8 ms, 192 KB JSON, 46.5 MB peak memory
    - offset fallback when sorting by a non-key column at offset 100k: 1.4 s (documented)
- **Not verified yet:**
    - 10M-row benchmark and real-MySQL integration tests (both need the `dbnova_test` user)
    - Browser UI check by the user
- **Security self-review:**
    - Every identifier, including sort and filter columns, is checked against `information_schema.COLUMNS` before reaching SQL, then quoted. Filter values are bound.
    - The cursor and row key are base64 JSON lists of scalars, validated server-side, and only ever used as bound values.
    - Large cells are previewed or described, never sent raw. Binary is never sent as text.
    - No `v-html`. Every new route is behind `auth` + `EnsureMySQLCredentials`.
    - No findings.
- **Deviation:** `TableTabs.vue` became `TableHeader.vue` (header + tabs + breadcrumbs).

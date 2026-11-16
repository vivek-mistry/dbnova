# Phase 3 — Table browser & data grid

**Status:** done
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
- [x] Stress fixture: `dbnova:stress:seed-table` (10M rows into `dbnova_test_stress`, run as the `dbnova_test` user). It refuses non-`dbnova_test_` targets. Ran: 10M rows in about 7 minutes

### Tests (3a)

- [x] Unit: page query builder (keyset/offset, composite keys, direction, filters, NULL operators, injection through column names)
- [x] Feature: controllers (404 for an unknown table, validation of sort/filter/limit, cursor round-trip, JSON shapes)
- [x] Integration: keyset pages are consistent and non-overlapping, descending pages, filters/counts, offset fallback for keyless tables, truncation/binary handling. Ran against real MySQL as `dbnova_test`: passing
- [x] Benchmark: first + deep page on 10M rows. Page 1: 1.4 ms query, deep page (id > 9.9M): 1.4 ms, peak memory 46.5 MB (PERFORMANCE.md)

## 3b — Edit

- [x] `RecordController`: insert, update (by PK/unique key), delete (single + bulk). Rejected for tables without a key. Audited, and blocked in read-only mode
- [x] Inline cell editing (`CellEditor.vue`: double-click, Enter/blur saves, Esc cancels, NULL button) with optimistic update + rollback through `useRecordEditor`, `InsertRowDialog.vue` (Default / NULL / Value per column), row checkboxes + `DeleteRowsDialog.vue`
- [x] `CommandPalette.vue` (Cmd/Ctrl+K): pages and databases, and `database.` lists that database's tables

### Tests (3b)

- [x] Feature (`RecordControllerTest`): insert/update/delete, audit holds column names only (never values), '' and surrounding spaces reach MySQL unchanged, 422 for MySQL/read-only errors, validation, guests refused
- [x] `MySQLRecordManagerTest` (mocked connection): read-only, keyless refusal, binary/generated/unknown columns, UPDATE … LIMIT 1, row gone, key edit, transactional chunked delete, default inserts
- [x] Integration: update/delete only touch the targeted row, unchanged value still saves (FOUND_ROWS), deleted row reported, NULL vs '' on insert, constraint message. Ran against real MySQL as `dbnova_test`: passing

## Acceptance criteria

- A 10M-row table: first and deep pages each ≤ ~200 ms on suitable hardware, and PHP memory is flat. ✅ 1.4 ms / 1.4 ms query, 46.5 MB peak (PERFORMANCE.md).
- Edits save without a page reload, and failed edits roll back in the UI with the MySQL message. ✅ (3b) `useRecordEditor` + `RecordControllerTest`. Browser check by the user still pending.
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

### 3b

- **Shipped:**
    - `RecordManagerInterface` + `MySQLRecordManager` (insert/update/delete by key, read-only guard, binary/generated columns refused, transactional chunked deletes)
    - `TableBrowserInterface::row()`, `RecordController` (JSON; 422 with MySQL's reason)
    - Inline editing, insert dialog, bulk delete, Cmd/Ctrl+K palette
- **Found by a test and fixed:** Laravel's `TrimStrings` / `ConvertEmptyStringsToNull` run before routing, so the first route-name exemption never matched. `''` became NULL and spaces were trimmed. They now exempt by path (`databases/*/tables/*`).
- **Found while building:**
    - `PDO::MYSQL_ATTR_FOUND_ROWS` makes "saved the same value" count as a match.
    - On PHP 8.4+ the constant comes from `Pdo\Mysql`, to avoid deprecation warnings on CI's PHP 8.5.
- **Not verified yet:** no real-MySQL write has been run, deliberately. Writes are only tested against throwaway `dbnova_test_*` databases once the test user exists, and never against the user's real databases. The browser UI is still unchecked.
- **Security self-review:**
    - Column names are checked against the table, then quoted. Values and keys are bound.
    - Every UPDATE has `LIMIT 1` + a full-key WHERE.
    - Deletes need keys and are capped at 1,000 per request.
    - Audit entries store column names / counts, never row values.
    - Read-only mode is enforced in the adapter, and the UI hides edit controls.
    - No findings.

### Real-MySQL verification (user-created `dbnova_test` user, password `test`, limited to `dbnova_test` / `dbnova_test_*`)

- All 19 integration tests pass, including the new end-to-end test (login → create DB → browse → filter → edit → insert → delete → rename → drop → audit trail) with nothing mocked.
- **Critical bug found and fixed:** `MySQLConnector` merged PDO options with `...$options`. PHP renumbers integer keys on spread, so `MySQLConnection`'s options (native prepares, fetch mode, FOUND_ROWS) were silently never applied since Phase 2. Fixed with `array_replace`, and guarded by `MySQLConnectionIntegrationTest`. Before the fix, 2 of 17 real-MySQL tests fail; after it, all pass.
- 10M-row benchmark recorded in PERFORMANCE.md.

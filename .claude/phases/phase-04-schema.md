# Phase 4 — Schema management

**Status:** done
**Goal:** Create and alter tables, columns, indexes and foreign keys with a mandatory DDL preview.
**New dependencies:** none (the SQL preview is a `<pre>` until CodeMirror arrives in Phase 5).
**Read first:** `docs/DATABASE_ADAPTERS.md`, `docs/SECURITY.md` (identifiers), the `dbnova-adapter` skill.
**Decisions (user, 2026-10-30):**

- **Online first.** Try `ALGORITHM=INSTANT`, then `INPLACE, LOCK=NONE`, and only then a copying ALTER. A copying ALTER on a table over `limits.locking_ddl_confirm_rows` (1M) needs explicit confirmation.
- **Per-action dialogs** on the Structure tab, plus a Create-table form on the database page.

## Tasks

- [x] DTOs: `ColumnDefinition` (+ `ColumnDefault`), `IndexDefinition`, `ForeignKeyDefinition`, `ForeignKeyInfo`, `SchemaChange` value objects (add/modify/drop column, add/drop index, add/drop foreign key, rename/copy/truncate/drop table, create table), `DdlPlan`
- [x] `MySQLDdlBuilder` (pure, unit-tested): type whitelist, length/precision rules, enum/set values, unsigned/auto-increment/default/on-update/collation/comment/position, index and FK clauses, every change type
- [x] `TableManagerInterface` + `MySQLTableManager`: `preview()`, `apply()` (online-first algorithm ladder, locking confirmation), `foreignKeys()`. Audited, and blocked in read-only mode
- [x] `SchemaChangeController` (JSON preview/apply) + `SchemaChangeRequest` (per-type validation). Destructive changes (drop column/table, truncate) need the typed name + MySQL password
- [x] Structure tab: Add/Edit/Drop column, Add/Drop index, Add/Drop foreign key, table operations (rename, copy, truncate, drop), and a SQL preview with a Run step
- [x] Database page: Create table dialog (columns + primary key + engine/collation)
- [x] Views: listed on the database page and shown read-only on Structure (CREATE VIEW). Schema editing is hidden for views

## Tests

- [x] Unit: DDL generation for each type and change kind, plus injection attempts through identifiers, defaults, enum values, comments
- [x] Feature: controller validation, destructive confirmation, read-only, audit, 422 MySQL errors, locking confirmation round-trip
- [x] Integration (real MySQL, `dbnova_test`): apply each change, read back through `TableBrowser`/`SchemaManager`, plus the algorithm fallback and the locking-confirmation path

## Acceptance criteria

- Every schema change shows its exact SQL before running. ✅ The preview and apply use the same builder, and the integration test asserts the statements are identical.
- MySQL errors (for example an FK type mismatch) appear in the UI without leaving partial UI state. ✅ Returned as 422 with MySQL's reason, shown in the dialog. Nothing in the page changes until a change succeeds and the page reloads.
- Changes to big tables don't silently lock them. ✅ The INSTANT → INPLACE/LOCK=NONE → COPY ladder. COPY on more than 1M rows returns 409 until confirmed, verified on real MySQL.

## Completion notes

- **Shipped:**
    - 21 schema DTOs
    - `MySQLDdlBuilder` (pure, 48 unit cases including injection attempts)
    - `TableManagerInterface` + `MySQLTableManager` (preview, apply with the online ladder, foreignKeys, columnTypes, engines)
    - `SchemaChangeController` + `SchemaChangeRequest` (JSON preview/apply, 409 for locking, 422 for MySQL/validation errors, typed name + password for drop column, truncate and drop table)
    - Structure tab actions (add/edit/drop column, add/drop index, add/drop FK, rename/copy/truncate/drop table) and Create table on the database page
    - `SchemaChangeDialog` (form → SQL preview → Run → locking confirmation)
- **Added:** `ColumnInfo.collation`, so editing a column re-states its collation instead of silently resetting it to the table default.
- **Real MySQL (as `dbnova_test`):**
    - Add column: INSTANT, 28 ms on 300k rows
    - Add index: INPLACE, 176 ms
    - Type change: stops for confirmation, then runs as a COPY (1.8 s)
    - FK type mismatch: shows MySQL's reason
    - 7 table-manager integration tests and the end-to-end test (which now creates its table through `schema.preview`/`schema.apply`). The full suite passes 276/276 against real MySQL.
- **Views:** listed on the database page, and shown read-only on Structure with their CREATE VIEW. Schema editing is hidden for views.
- **Known limitations:**
    - FK referenced columns are typed by hand (comma-separated, defaulting to `id`).
    - FK targets are limited to the same database.
    - Literal quoting uses the server's `PDO::quote`, so the preview shows `\'`-style escapes.
- **Security self-review:**
    - Types and engines are whitelisted. Lengths are checked with strict regexes.
    - Collations are regex-checked, then checked against `information_schema`.
    - All identifiers go through `IdentifierQuoter`, and all literals (defaults, ENUM values, comments) through the driver's quote.
    - Destructive changes need the typed name + MySQL password at apply time.
    - Read-only mode is enforced in `apply()`.
    - Audit entries store names only.
    - No findings.
- **Not verified:** the browser UI. Nobody has clicked through the dialogs yet.

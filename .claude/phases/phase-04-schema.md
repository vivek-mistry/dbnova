# Phase 4 — Schema management

**Status:** not started
**Goal:** Create and alter tables, columns, indexes and foreign keys with a mandatory DDL preview.
**New dependencies:** none (the SQL preview can use a `<pre>` until CodeMirror arrives in Phase 5).
**Read first:** `docs/DATABASE_ADAPTERS.md` (TableManager), `docs/SECURITY.md` (identifiers).

## Tasks
- [ ] `TableManagerInterface` + `MySQLTableManager`: create, alter (a list of `TableChange` value objects), drop, truncate, rename, copy structure
- [ ] DDL generator: column types (numeric, string, temporal, JSON, enum/set, spatial best-effort), defaults, nullability, auto-increment, charset/collation, comments
- [ ] Index management: primary, unique, index, fulltext. Foreign keys: on update/delete actions
- [ ] `previewSql()` endpoint, then execute after confirmation. Audited, and blocked in read-only mode
- [ ] `StructureController` + `pages/tables/Structure.vue` (editable), `pages/tables/Create.vue`
- [ ] Views: list them and show their definitions (read-only)
- [ ] Typed-name confirmation dialog component (reused for drop/truncate)

## Tests
- [ ] Unit: DDL generation snapshot tests for each type and change kind, plus identifier injection attempts
- [ ] Integration: apply changes, then read back through `SchemaManager` and compare

## Acceptance criteria
- Every schema change shows its exact SQL before running.
- MySQL errors (for example an FK type mismatch) appear in the UI without leaving partial UI state.

## Completion notes
_(filled by /phase-done)_

# Phase 5 — SQL editor

**Status:** not started
**Goal:** A fast, safe SQL editor with multi-statement execution, history and cancellation.
**New dependencies (approval needed):** `codemirror`, `@codemirror/lang-sql` (plus `@codemirror/state`, `@codemirror/view` if not bundled).
**Read first:** `docs/IMPORT_EXPORT_ENGINE.md` (scanner), `docs/SECURITY.md` (read-only whitelist).

## Tasks
- [ ] `app/Database/Sql/SqlStreamScanner` (shared with Phase 6): statement splitting with quotes, comments, conditional comments, `DELIMITER`
- [ ] `QueryExecutorInterface` + `MySQLQueryExecutor`: per-statement results (rows capped, columns, affected rows, warnings, timing), unbuffered cursor, `max_execution_time`
- [ ] Read-only mode: first-keyword whitelist + `SET SESSION TRANSACTION READ ONLY`
- [ ] Cancel: return the connection id, then `KILL QUERY` over a second connection
- [ ] `QueryHistory` model (+ saved queries flag), with history and saved panels
- [ ] `components/editor/SqlEditor.vue` (CodeMirror, MySQL dialect, schema autocomplete from a metadata endpoint), result tabs reuse `DataGrid` read-only
- [ ] `pages/sql/Index.vue`, plus a SQL tab on database and table pages (prefilled context)
- [ ] "Export full result" hook (wired up in Phase 7)

## Tests
- [ ] Unit: scanner edge cases (`;` in strings, escaped quotes, `--` vs `--x`, `#`, `/*! */`, `DELIMITER $$`, chunk-boundary splits)
- [ ] Integration: an error in statement 2 of 3 is reported correctly, the row cap works, and cancel kills a `SLEEP(30)`

## Acceptance criteria
- Invalid SQL always shows the MySQL error, and it's never shown as success.
- A `SELECT` over 10M rows returns the capped rows fast, and memory is flat.
- Long queries can be cancelled from the UI.

## Completion notes
_(filled by /phase-done)_

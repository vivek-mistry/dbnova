# Phase 5 — SQL editor

**Status:** done
**Goal:** A fast, safe SQL editor with multi-statement execution, history and cancellation.
**New dependencies (approved 2026-11-13):** `codemirror@^6.0.2`, `@codemirror/lang-sql@^6.10.0`.
**Decisions (user, 2026-11-13):**

- **Stop at the first error.** Earlier statements stay applied (no wrapping transaction). The failing statement shows MySQL's error, and the rest are listed as not run.
- **Confirm destructive statements first:** DROP, TRUNCATE, and UPDATE/DELETE without a top-level WHERE.
  **Read first:** `docs/IMPORT_EXPORT_ENGINE.md` (scanner), `docs/SECURITY.md` (read-only whitelist).

## Tasks

- [x] `app/Database/Sql/SqlStreamScanner` (shared with Phase 6): statement splitting with quotes, comments, conditional comments, `DELIMITER`
- [x] `QueryExecutorInterface` + `MySQLQueryExecutor`: per-statement results (rows capped, columns, affected rows, warnings, timing), unbuffered cursor, `max_execution_time`
- [x] Read-only mode: first-keyword whitelist + `SET SESSION TRANSACTION READ ONLY`
- [x] Cancel: return the connection id, then `KILL QUERY` over a second connection
- [x] `QueryHistory` model (+ saved queries flag), with history and saved panels
- [x] `components/editor/SqlEditor.vue` (CodeMirror, MySQL dialect, schema autocomplete from a metadata endpoint), result tabs reuse `DataGrid` read-only
- [x] `pages/sql/Index.vue`, plus a SQL tab on database and table pages (prefilled context)
- [ ] "Export full result" hook. **Deferred to Phase 7** (the export engine). Results say "add a LIMIT or export the full result" when capped
- [x] (added) Session `max_execution_time` (`limits.query_timeout_ms`, 30 s) on every DBNova connection, so slow browse sorts/filters are stopped too (Phase 3 follow-up), with a friendly message for error 3024

## Tests

- [x] Unit: scanner edge cases (`;` in strings, escaped quotes, `--` vs `--x`, `#`, `/*! */`, `DELIMITER $$`, chunk-boundary splits)
- [x] Integration: an error in statement 2 of 3 is reported correctly, the row cap works, and cancel kills a `SLEEP(30)`

## Acceptance criteria

- Invalid SQL always shows the MySQL error, and it's never shown as success. ✅ Statement status `error` with MySQL's code/message and line. Later statements are `skipped`, and history records `error` (integration test).
- A `SELECT` over 10M rows returns the capped rows fast, and memory is flat. ✅ `SELECT * FROM orders` (10M rows): 1,000 rows in 11 ms, +2 MB. The rest is stopped server-side with `KILL QUERY`.
- Long queries can be cancelled from the UI. ✅ The run id maps to the MySQL connection id (cache), and Cancel sends `KILL QUERY` from a second connection. Verified on real MySQL against a `SLEEP(20)` (stopped in under 5 s). SELECTs also stop at the 30 s `max_execution_time`.

## Completion notes

- **Shipped:**
    - `SqlStreamScanner` + `SqlStatement` (incremental, chunk-safe, line numbers, keyword/read-only/destructive classification)
    - `QueryExecutorInterface` + `MySQLQueryExecutor`:
        - per-statement results, stop at first error
        - unbuffered rows with the cap enforced by `KILL QUERY`
        - warnings, affected rows, insert id
        - read-only whitelist + `SET SESSION TRANSACTION READ ONLY`
        - destructive confirmation (409)
    - `CellEncoder`
    - `QueryHistory` (per user, newest 200 unsaved kept, saved queries by name)
    - `QueryController` (page, run, cancel, schema, save)
    - `SqlEditor.vue` (CodeMirror MySQL dialect + schema autocomplete), `ResultGrid.vue`, `pages/sql/Index.vue` (database picker, Run/Cancel, result tabs, history and saved panels, destructive confirmation)
    - A SQL tab on table pages and a SQL button on database pages
- **Added:** every DBNova connection sets `max_execution_time` (30 s), so slow unindexed sorts/filters in the table browser are stopped too (Phase 3 follow-up). Error 3024 gets a plain message.
- **Decisions in code:**
    - The user's SQL is sent with emulated prepares, i.e. the text protocol, because some statements can't be server-side prepared.
    - CodeMirror custom keys and schema swaps avoid importing `@codemirror/view`/`@codemirror/state` (not approved dependencies). Ctrl/Cmd+Enter is caught on the wrapper, and the editor is rebuilt (keeping its text) when the database changes.
- **Real MySQL:**
    - 8 executor integration tests: cap, stop-at-error with line numbers, affected rows/insert id/warnings, destructive confirmation, read-only, timeout, cancel of a `SLEEP(20)` on another connection, cancelling a vanished connection
    - Full suite 359/359 against real MySQL (PHP 8.4.27)
- **Known limitations:**
    - Long text in results is cut to a 1,024-character preview, and the full value of an arbitrary result cell can't be fetched (use the table browser).
    - Backslash escapes are assumed (`NO_BACKSLASH_ESCAPES` isn't detected).
    - `php artisan serve` with a single worker can't process Cancel while a query runs. Herd/FPM can.
- **Security self-review:**
    - The editor runs arbitrary SQL by design: auth + CSRF, read-only whitelist enforced server-side before anything runs, destructive confirmation, a 1 MB SQL cap.
    - Cancel only works with a run id registered by the same user, and MySQL only lets an account kill its own threads anyway.
    - History is per user. Audit entries record statement counts and keywords, never SQL text or values.
    - No findings.
- **Not verified:** the browser UI. Nobody has clicked through the editor yet.

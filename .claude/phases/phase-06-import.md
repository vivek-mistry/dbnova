# Phase 6 — Large import engine (highest risk)

**Status:** done (6a committed d34563d; 6b committed after user approval 2026-11-13)
**Goal:** Reliable, honest import of `.sql` / `.sql.gz` / `.zip` dumps of 10 GB+ into one selected database.
**New dependencies:** none.
**Read first:** `docs/IMPORT_EXPORT_ENGINE.md` (the spec for this phase), `docs/adr/0006-*.md`, the `dbnova-import-export` skill.

## Tasks

### Upload (6a)

- [x] `Upload` model + migration (uuid, original_name, format, size, chunk_size, total_chunks, status, completed_at). Received chunks are marker files, not a JSON column (safe under parallel chunk requests). No SHA-256: each chunk's length is checked, and gzip/zip carry their own CRCs
- [x] `UploadController`: create, put chunk, status, cancel. There's **no assemble step**: chunks are written at their offset into one file. Rate-limited (`throttle:uploads`) and CSRF-protected (`X-XSRF-TOKEN`)
- [x] `useChunkedUpload` composable (3 parallel slices, retries with backoff, resume from status, upload id saved in localStorage per file)
- [x] Disk-space precheck (file size + `min_free_disk_bytes`)

### Source & preflight (6a)

- [x] `SourceStream` (sql / gz / zip) with zip safety checks
- [x] `ImporterInterface::preflight()` using `SqlStreamScanner`, producing a `PreflightReport` (db references, decision, table names, statement count, warnings). Conflicts are computed when the report is shown
- [x] Preflight runs as a queued step with progress (`PreflightImportJob`)
- [x] `SqlStreamScanner` rewritten for throughput (16 → 103 MB/s), plus `isInsideLiteral()`, a statement size limit and per-statement delimiters

### Import (6b)

- [x] `ImportJob` model + migration (all columns, including encrypted `credentials`; used by preflight in 6a)
- [x] `ImportDatabaseJob` on the `imports` queue (`tries=1`, long timeout)
- [x] `MySQLImporter::import()`: `ProcessRunner::stream()` (generator stdin with backpressure + 1 ms tick loop) (`--binary-mode`, `--local-infile=0`), pump loop with scanner filter, throttled progress, cancellation, stderr capture with original line numbers
- [x] Optional speed prelude (FK/unique checks off, autocommit off; off by default) with an honest UI note
- [x] `dbnova:recover-jobs` (every minute; also covers a stale `preflight`) and `dbnova:prune-temp` (hourly; uploads, failed imports) in the scheduler
- [x] Set the queue `retry_after` config for the database connection (25 h)
- [x] Audit log entry when an import starts and ends

### UI

- [x] `pages/import-export/Import.vue` wizard: file + target → upload → preflight report (6a); run → result (6b)
- [x] `ImportStatusCard.vue` polling every 2 s, with honest labels (preflight states; import states in 6b)
- [x] Failure screen: partial-data warning + "Drop & recreate target" (typed + password confirm)
- [x] Import history list
- [x] Health page: a worker busy with an import counts as alive (the import reports its own heartbeat)

### Fixtures & tests

- [x] `tests/Fixtures/dumps/*` (single-db, plain, multi-db, invalid). gz, zip, zip-traversal, symlink and truncated variants are generated in the tests
- [x] Unit: SourceStream, scanner additions. Feature: preflight rules, skip filter, uploads, imports, preflight job, health check
- [x] Integration: import fixtures and verify row counts; invalid SQL → `failed`; cancel → `cancelled`
- [x] Recovery: a stale heartbeat → `interrupted`
- [x] Stress: `dbnova:stress:make-dump --size=2G` (the user chose 2 GB + extrapolation), with results recorded in PERFORMANCE.md

## Acceptance criteria

- A 10 GB dump imports with worker memory ≤ ~64 MB, and table and row counts match the source.
- An interrupted upload resumes without re-sending received chunks.
- Invalid SQL is never reported as success, and the error message and position are shown.
- A multi-database dump is rejected in preflight.
- Killing the worker → the job shows `interrupted` within ~2 minutes.

## Completion notes

### 6a — upload + preflight (2026-11-13, awaiting review)

- Chunks go straight to their offset in one file, with a marker per chunk. That means no assembly, 1× disk, and parallel/retried chunks are safe.
- `SourceStream` reads sql / gz (incremental inflate, multi-member, truncation detected) / zip (streamed, never extracted; traversal, symlink, encrypted and multi-file archives refused).
- Preflight: a 353 MB real dump takes 4.6 s plain / 3.7 s gzip with +5 MB peak memory (about 2 min for 10 GB). It rejects multi-db dumps, database-qualified tables, truncated files, empty files and statements over 64 MB. It warns about DEFINER, GTID_PURGED and LOAD DATA LOCAL.
- The new scanner returns byte-identical statements to the Phase 5 scanner on the real dump at chunk sizes from 1 MB down to 333 bytes.
- **Security review** (dbnova-security-reviewer) found the leading-token check bypassable, plus scanner/client splitting differences. Fixed with `MySQLDumpPolicy`: a statement allowlist, qualified-name checks in every table-name position, and refusal of client-desync text. The scanner now follows the client's `--` rule, confirmed against the real 8.0.39 client. Also fixed:
    - unbounded buffer on a DELIMITER line with no newline;
    - an oversized chunk could overwrite the next chunk;
    - a chunk could re-create a cancelled upload's file;
    - system databases are now refused as targets;
    - per-user cap of 3 unfinished uploads, and a throttle on starting uploads;
    - zip archives with more than 1,000 entries are refused before ZipArchive loads them.
      The single-database rule is documented as best-effort; MySQL grants are the boundary.
- Tests: 467+ passing against real MySQL. `composer ci:check` is green.
- Not verified in a browser yet (uploading, pause/resume, reload-resume).

### 6b — import job, cancel/recovery, failure screen (2026-11-13, awaiting review)

- `ProcessRunner::stream()`: a generator feeds the `mysql` client's stdin (Symfony pulls one chunk at a time: backpressure), with a 1 ms tick loop for heartbeats and cancel that runs even while MySQL works on one long statement. Cancel sends SIGTERM, then SIGKILL after 5 s.
- `MySQLImportPump` re-emits each kept statement exactly as written and pads blank lines, so MySQL's "at line N" is the line in the user's file. This was checked on real MySQL: line 9 for the invalid fixture, and line 14 after skipped statements, comments and a DELIMITER procedure. It also re-emits DELIMITER, terminates statements that end in `--` comments, and re-checks `MySQLDumpPolicy` for every statement.
- The `mysql` client runs with `--binary-mode --local-infile=0 --skip-reconnect`. Before starting, the importer refuses a server with global `NO_BACKSLASH_ESCAPES` and a target that no longer exists. Read-only mode is enforced in the adapter.
- `ImportDatabaseJob`: claims Queued→Importing, credentials are wiped on every end state, a completed import deletes its file and a failed one keeps it, everything is audited (`import.start/completed/failed/cancelled/interrupted/reset-target`). The `failed()` hook says "data may be partially imported".
- `dbnova:recover-jobs` (every minute: stale heartbeat → `interrupted`, a stale check → `failed`) and `dbnova:prune-temp` (hourly: expired uploads, leftover `.cnf` credential files older than 1 h).
- UI: start with a "Faster import" option (off by default, with an honest note). Progress shows the % of the file sent plus SQL bytes and statements, with "sent is not committed". Completed shows warnings. Failed/cancelled/interrupted after sending shows the error with its line, "Data may be partially imported", and "Drop & recreate target" (typed name + MySQL password) or "Keep as is".
- **Stress (2 GB, 20.1M rows):** preflight 37 s, import 117 s at 17.6 MB/s (MySQL-bound). PHP peak +6 MB (30 MB process) and flat; row counts exact. 10 GB extrapolates to ≈ 13 min with the same memory.
- **Security review 6b** (reproduced against real MySQL) found:
    - **F1:** a `;` inside one client statement under `DELIMITER ;;` runs a second, unchecked statement.
    - **F2:** a quoted `DELIMITER 'x'` splits differently in the client.
    - **F3:** trimmed whitespace before a `$$` delimiter.
    - **F4:** credentials on never-started queued imports.
    - **F5:** an orphaned `mysql` client racing "Drop & recreate".
    - **F6:** no throttle on password-confirm endpoints.

    All are fixed and have regression tests that run the reviewer's exact attacks against real MySQL:
    - inner `;` is refused, and compound routine/trigger/event bodies need explicit trust at Start;
    - only plain delimiters are accepted, and the gap before the delimiter is kept;
    - the worker wipes credentials once the client has them, and stale queued imports are failed;
    - reset is refused while sessions or imports are active in the target;
    - reset and database drop are throttled to 5 per minute.

- Tests: 512 passing against real MySQL (47 skip without it). `composer ci:check` is green.
- Not verified in a browser yet, and the "kill the worker" drill was covered by tests (recover-jobs), not done live.

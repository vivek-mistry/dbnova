# Phase 6 — Large import engine (highest risk)

**Status:** not started
**Goal:** Reliable, honest import of `.sql` / `.sql.gz` / `.zip` dumps of 10 GB+ into one selected database.
**New dependencies:** none.
**Read first:** `docs/IMPORT_EXPORT_ENGINE.md` (the spec for this phase), `docs/adr/0006-*.md`, the `dbnova-import-export` skill.

## Tasks

### Upload

- [ ] `Upload` model + migration (uuid, original_name, size, chunk_size, received bitmap/json, status, sha256, expires_at)
- [ ] `UploadController`: create, put chunk, status, assemble, cancel. Rate-limited and CSRF-protected
- [ ] `useChunkedUpload` composable (slices, retries with backoff, resume from status, upload id saved in localStorage)
- [ ] Disk-space precheck

### Source & preflight

- [ ] `SourceStream` (sql / gz / zip) with zip safety checks
- [ ] `ImporterInterface::preflight()` using `SqlStreamScanner`, producing a `PreflightReport` (db references, decision, table names, conflicts, statement count)
- [ ] Preflight runs as a queued step with progress (large files)

### Import

- [ ] `ImportJob` model (status enum, bytes_streamed, statements_seen, error_count, last_error, cancel_requested, worker_pid, heartbeat_at, options json, `credentials` encrypted with `Crypt` and wiped on terminal states — ADR 0008)
- [ ] `ImportDatabaseJob` on the `imports` queue (`tries=1`, long timeout)
- [ ] `MySQLImporter::import()`: Process + `InputStream`, pump loop with scanner filter, throttled progress, cancellation, stderr capture
- [ ] Optional speed prelude (FK/unique checks off, autocommit off) with an honest UI note
- [ ] `dbnova:recover-jobs` (every minute) and `dbnova:prune-temp` (hourly) in the scheduler
- [ ] Set the queue `retry_after` config for the database connection

### UI

- [ ] `pages/import-export/Import.vue` wizard (file → upload → target → preflight → run → result)
- [ ] `ImportStatusCard.vue` polling every 2 s, with honest labels
- [ ] Failure screen: partial-data warning + "Drop & recreate target" (typed + password confirm)
- [ ] Import history list

### Fixtures & tests

- [ ] `tests/Fixtures/dumps/*` (single-db, with-use, multi-db, invalid, gz, zip, zip-traversal)
- [ ] Unit: SourceStream, scanner filter (skip USE/CREATE DATABASE for the single name)
- [ ] Integration: import fixtures and verify row counts; invalid SQL → `failed`; multi-db → `rejected`; cancel → `cancelled`
- [ ] Recovery: a stale heartbeat → `interrupted`
- [ ] Stress: `dbnova:stress:make-dump --size=10G`, with results recorded in PERFORMANCE.md

## Acceptance criteria

- A 10 GB dump imports with worker memory ≤ ~64 MB, and table and row counts match the source.
- An interrupted upload resumes without re-sending received chunks.
- Invalid SQL is never reported as success, and the error message and position are shown.
- A multi-database dump is rejected in preflight.
- Killing the worker → the job shows `interrupted` within ~2 minutes.

## Completion notes

_(filled by /phase-done)_

# Phase 7 — Export engine

**Status:** in progress (7a committed; 7b not started)
**Goal:** Export databases and tables to SQL / SQL.GZ and tables or query results to CSV, without exhausting memory.
**New dependencies:** none.
**Read first:** `docs/IMPORT_EXPORT_ENGINE.md` (Export section).

**User decisions (2026-11-13):**

- Split into 7a/7b.
- The dump names no database (no CREATE DATABASE/USE) and uses `--set-gtid-purged=OFF`.
- CSV always goes through the queued job.
- Stress test: 2 GB plus extrapolation.

## Tasks

### SQL export (7a)

- [x] `ExportJob` model + migration (db, tables json, format, options, estimated/written/file bytes, status, expires_at, `credentials` encrypted and wiped once the worker has them — ADR 0008)
- [x] `ExporterInterface` + `MySQLExporter`: `mysqldump` with the documented flags, names after `--`, stdout streamed to a file (`ProcessRunner::streamOutput`), with optional incremental gzip; partial files are deleted
- [x] `ExportDatabaseJob` on the `exports` queue, with progress, a labelled estimate, cancel and a disk-space guard
- [x] Signed, expiring download route (`BinaryFileResponse`, owner check). Expired files are pruned; leftovers are cleaned by `dbnova:prune-temp`, stalled exports by `dbnova:recover-jobs`
- [x] `pages/import-export/Export.vue` (choose DB/tables, format, content, routines) + export history, with Import/Export tabs

### CSV (7b)

- [ ] CSV: an unbuffered PDO cursor → `fputcsv`, always through the job (user decision)
- [ ] "Export full result" from the SQL editor (Phase 5 hook; read-only statements only)

## Tests

- [x] Integration: export → re-import through Phase 6 → identical checksums, tables, views and routines (`.sql` and `.sql.gz`)
- [x] Feature: signed URL expiry, owner checks, read-only mode still allows export, job states, recovery and pruning
- [x] Stress: a 2 GB export keeps memory flat (recorded in PERFORMANCE.md)

## Acceptance criteria

- Multi-GB exports complete with flat worker memory.
- Exported dumps re-import cleanly with DBNova.
- Download links expire as configured.

## Completion notes

### 7a — SQL export (2026-11-13, committed)

- `MySQLExporter` runs `mysqldump --single-transaction --quick --hex-blob --default-character-set=utf8mb4 --set-gtid-purged=OFF --no-tablespaces [--routines --events --triggers] -- db [tables]`. It writes stdout straight to a `fopen('xb')` file, through incremental `deflate_add` for `.sql.gz`. The file is deleted on a non-zero exit, a missing `-- Dump completed` trailer, a short write, a cancel, low disk, or a crash.
- `ProcessRunner::streamOutput()` uses Symfony Process directly with `clearOutput()`. It only sleeps when idle, so throughput isn't capped.
- The job claims the export, wipes the credentials, writes progress every 2 s, and checks the cancel flag and free disk. The download link is temporary and signed (≤ 60 min, never past the file's 24 h expiry) and also requires the owner. Recovery covers stalled and unstarted exports, and pruning covers expired files and leftovers.
- UI: Import / Export tabs. The Export page has database, all or chosen tables (filter, sizes), format, content and routines options, a live status card (SQL dumped plus a labelled catalog estimate), Download / Delete file, and history.
- **Stress (10M rows, 3.7 GB of SQL):**
    - `.sql.gz`: 26 s, 261 MB file.
    - `.sql`: 62 s.
    - PHP memory went from 25 to 29 MB and stayed flat.
    - Both re-imported through DBNova with identical row counts and `CHECKSUM TABLE`. 10 GB extrapolates to about 70 s (gz) or 3 min (plain).
- **Security review 7a** (dbnova-security-reviewer):
    - **HIGH-1:** a CR/LF in the MySQL password injected options into the `--defaults-extra-file`, so `result-file=` let mysqldump overwrite any file the worker can write. This was verified on 8.0.39, and the code is shared with Phase 6 imports. **Fixed:** `DefaultsFile` refuses CR/LF/NUL in every value, and login refuses them, with tests.
    - **LOW-1:** a late finish after recovery could audit `export.completed` for an interrupted export. **Fixed:** the audit only runs when the status update applied, and the file is deleted otherwise. Tested.
    - **LOW-2** (not fixed, needs a decision): there's no per-user cap on _completed_ exports held for 24 h. One user can keep the disk near `min_free_disk_bytes`.
    - **LOW-3:** no rate limit on cancel/destroy (owner-scoped, harmless).
    - Everything else was found sound: argv after `--`, credentials, signed downloads, filenames, paths, cleanup, read-only, XSS.
- Tests: 556 passing against real MySQL (57 skip without it). `composer ci:check` is green.
- Not verified in a browser yet.

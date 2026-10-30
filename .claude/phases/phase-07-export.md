# Phase 7 — Export engine

**Status:** not started
**Goal:** Export databases and tables to SQL / SQL.GZ and tables or query results to CSV, without exhausting memory.
**New dependencies:** none.
**Read first:** `docs/IMPORT_EXPORT_ENGINE.md` (Export section).

## Tasks
- [ ] `ExportJob` model (db, tables json, format, options, bytes_written, status, path, expires_at)
- [ ] `ExporterInterface` + `MySQLExporter`: `mysqldump` with the documented flags, stdout streamed to a file, with optional incremental gzip
- [ ] `ExportDatabaseJob` on the `exports` queue, with progress + estimate (labelled)
- [ ] CSV: an unbuffered PDO cursor → `fputcsv`. Small exports are a direct streamed response, large ones go through the job
- [ ] Signed, expiring download route (streamed response). Expired files are pruned
- [ ] `pages/import-export/Export.vue` (choose DB/tables, format, options) + export history
- [ ] "Export full result" from the SQL editor (Phase 5 hook)

## Tests
- [ ] Integration: export → re-import through Phase 6 → identical row counts and schemas
- [ ] Feature: signed URL expiry, read-only mode still allows export
- [ ] Stress: a multi-GB export keeps memory flat (recorded in PERFORMANCE.md)

## Acceptance criteria
- Multi-GB exports complete with flat worker memory.
- Exported dumps re-import cleanly with DBNova.
- Download links expire as configured.

## Completion notes
_(filled by /phase-done)_

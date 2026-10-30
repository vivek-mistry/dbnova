---
name: dbnova-import-export
description: Rules for DBNova's chunked upload, SQL import, export and streaming code (Upload/ImportJob/ExportJob, SqlStreamScanner, SourceStream, MySQLImporter/Exporter, queue jobs). Use when touching any import, export, upload or streaming code.
---

# DBNova Import / Export

`.claude/docs/IMPORT_EXPORT_ENGINE.md` is the spec, and `docs/adr/0006-*.md` explains the reasoning. Read both.

## Non-negotiables
1. **Stream everything.** Fixed-size reads (1 MB pump, 8–16 MB upload chunks). Never use `file_get_contents`, `file()`, `gzfile`, `ZipArchive::getFromName`, `fetchAll` on dumps, or string accumulation of output.
2. **One destination database.** The scanner decides: 0 or 1 referenced DB is OK (skip its `CREATE DATABASE`/`USE`/`DROP DATABASE`), 2 or more are rejected. Never use regex rewriting.
3. **Honest status.** Show bytes *sent* to MySQL, never "% imported/committed". Never promise rollback. Failures say the data may be partial.
4. **Jobs hold IDs only.** No credentials, no file contents in payloads. `tries = 1`. Progress and heartbeat are written to the model at most every ~8 MB or 2 s.
5. **Cancellation** is cooperative through `cancel_requested`, checked in the pump loop. Stop the process with SIGTERM, then SIGKILL.
6. **Recovery:** never auto-resume an import. A stale heartbeat means `interrupted`.
7. **Cleanup** follows the retention config. Always clean up `DefaultsFile` in `finally`.
8. **Paths:** UUIDs under `storage/app/dbnova/{uploads,imports,exports,tmp}`. The user's filename is metadata only. Zip entries are validated (no `..`, absolute paths or symlinks, exactly one `.sql`).

## Testing checklist
- Fixtures in `tests/Fixtures/dumps/` for: plain, with-USE, multi-db, invalid, gz, zip, zip-traversal.
- Scanner: statements split across chunk boundaries, `DELIMITER $$` triggers, `/*!40101 ... */`, `;` inside strings, escaped quotes.
- State machine transitions, including cancelled and interrupted.
- Memory: assert `memory_get_peak_usage()` stays under a threshold while importing a generated ~200 MB fixture (stress tests cover 10 GB).

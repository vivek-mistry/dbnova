# Import / Export Engine

## Import — goals

- Handle `.sql`, `.sql.gz` and `.zip` (containing exactly one `.sql`) of **10 GB+**.
- PHP memory stays flat (≤ ~64 MB worker), and the whole file is never read into memory.
- Exactly **one destination database** per import.
- Status is always honest. There is no fake rollback.

## State machine

```
Upload:    uploading ──► uploaded ──► (expired)
                │
ImportJob:  pending ─► preflight ─► queued ─► importing ─► completed
                          │                     │  ├────► failed
                          └──► rejected         │  ├────► cancelled
                                                └──┴────► interrupted (stale heartbeat)
```

## 1. Chunked, resumable upload

- The client (`useChunkedUpload` composable) splits the `File` into 8–16 MB slices (configurable) and sends `PUT /uploads/{id}/chunks/{index}` with the byte offset.
- The server writes each chunk directly to `storage/app/dbnova/uploads/{id}/{index}.part`, never through memory as a whole. Duplicate chunks are idempotent.
- `GET /uploads/{id}` returns the received indexes. The client resumes by skipping them, which survives a page reload (the upload id is kept in localStorage).
- Assembly streams the parts into one file in order (`stream_copy_to_stream`), then deletes the parts. An optional SHA-256 is computed while streaming.
- Validation: the extension is in the allow-list, the declared size matches, and there's enough free disk (needs ≥ 2× file size for zip). Filenames are never used as paths. Storage uses UUIDs.
- PHP `upload_max_filesize` only needs to be larger than one chunk.

## 2. Source reader

`SourceStream` gives a uniform `read(int $bytes): string` and `bytesRead()`:

- `.sql` → `fopen(…, 'rb')`
- `.sql.gz` → `gzopen` (streaming, no temp file). Progress is measured in compressed bytes so it can be compared with the file size.
- `.zip` → `ZipArchive::getStream()` on the single `.sql` entry. Entries with `..` or absolute paths are rejected, and so are archives with ≠ 1 SQL entry.

## 3. Preflight (`SqlStreamScanner`)

A tokenizer that streams the source and tracks state: inside a single/double/backtick quote, a `--`/`#` line comment, a `/* */` block comment, a `/*!40101 … */` conditional comment (treated as executable), and the current `DELIMITER`. It produces statement boundaries and the **first keyword(s)** of each statement. It never rewrites statement bodies.

Rules:

| Found                                                                                  | Action                                                                                                                      |
| -------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------- |
| No `CREATE DATABASE` / `USE`                                                           | OK — import into the selected target                                                                                        |
| References to exactly one database name (`CREATE DATABASE [IF NOT EXISTS] x`, `USE x`) | OK — those statements are **skipped** at the token level during import, and the target is pinned with `--database=<target>` |
| References to ≥ 2 distinct databases                                                   | **Rejected** with a list of the names                                                                                       |
| `DROP DATABASE`                                                                        | Rejected unless it targets the same single name (then skipped)                                                              |

The full preflight scan streams the whole file once (I/O-bound, constant memory). The report also shows: statement count, detected `CREATE TABLE` names, whether the target DB already has tables with the same names (a conflict warning), and `SET FOREIGN_KEY_CHECKS` presence.

## 4. Import job

- `ImportDatabaseJob` runs on the `imports` queue with `tries = 1`, `timeout = 0` / very large, and the queue `retry_after` set above the max expected runtime (documented in INSTALL.md).
- `MySQLImporter`:
    1. Start `[mysql, --defaults-extra-file=…, --database=<target>, --default-character-set=<charset>, --batch, --show-warnings]` with a Symfony `InputStream` as stdin.
    2. Pump loop: read 1 MB → pass it through the scanner filter (drops skipped statements) → write to stdin.
    3. Every N MB or 2 s: update `bytes_streamed`, `statements_seen`, `heartbeat_at`, and check the `cancel_requested` flag.
    4. Collect stderr. A non-zero exit means `failed` with the last error and the line/statement number.
    5. Optional session prelude (the user picks): `SET FOREIGN_KEY_CHECKS=0; SET UNIQUE_CHECKS=0; SET autocommit=0` … `COMMIT` at the end. This only improves speed and does **not** make the import atomic.
- If MySQL loses the connection, the process exits non-zero and the job becomes `failed` with a "connection lost" message.

## 5. Progress shown in the UI

| Metric                     | Source                                                             |
| -------------------------- | ------------------------------------------------------------------ |
| File size                  | `uploads.size`                                                     |
| Uploaded                   | received chunks / total                                            |
| **Sent to MySQL**          | `bytes_streamed / source size` — labelled "sent", never "imported" |
| Statements processed       | `statements_seen`                                                  |
| Status / current operation | state machine                                                      |
| Elapsed                    | `started_at` → now                                                 |
| Errors                     | `error_count`, `last_error`                                        |

The UI polls `GET /imports/{id}` every 2 s while the job is active.

## 6. Cancellation & recovery

- Cancel sets `cancel_requested`. The pump loop stops the process (SIGTERM, then SIGKILL after a grace period) and the job becomes `cancelled`.
- `dbnova:recover-jobs` (scheduled every minute) marks `importing` jobs whose heartbeat is more than 2 minutes old as `interrupted`. There's no auto-resume, because the partial state is unknown.
- The failed/cancelled/interrupted screen explains that **data may be partially imported**, and offers "Drop & recreate target database" (typed confirmation) or "Keep as is".

## 7. Cleanup

- On `completed`, the assembled file and parts are deleted.
- On failed/cancelled/interrupted, they're kept for `retention.failed_import_hours` (default 24) so the import can be retried without re-uploading. After that, `dbnova:prune-temp` (hourly) deletes them.
- Incomplete uploads older than 24 h are pruned.

## Export

- `ExportDatabaseJob` runs `mysqldump --defaults-extra-file=… --single-transaction --quick --routines --triggers --events --hex-blob --default-character-set=utf8mb4 <db> [tables…]`.
- stdout streams to `storage/app/dbnova/exports/{uuid}.sql`, or through `deflate_init(ZLIB_ENCODING_GZIP)` incrementally to `.sql.gz`.
- Progress = bytes written (the total is unknown, so an estimate based on `information_schema` data_length is clearly labelled as one).
- Downloads use a temporary signed route with a streamed response. Files expire after `retention.export_link_minutes`.
- **CSV:** an unbuffered PDO cursor → `fputcsv` to the response stream (small) or to a file via the job (large).

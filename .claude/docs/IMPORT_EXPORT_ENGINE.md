# Import / Export Engine

## Import — goals

- Handle `.sql`, `.sql.gz` and `.zip` (containing exactly one `.sql`) of **10 GB+**.
- PHP memory stays flat (≤ ~64 MB worker), and the whole file is never read into memory.
- Exactly **one destination database** per import.
- Status is always honest. There is no fake rollback.

## State machine

```
Upload:    uploading ──► uploaded ──► (expired)
                └──► cancelled
ImportJob:  pending ─► preflight ─► ready ─► queued ─► importing ─► completed
               │           │                  │          │  ├────► failed
               │           └──► rejected      │          │  ├────► cancelled
               └──► cancelled (before a worker picks it up)  └──┴────► interrupted (stale heartbeat)
```

`ready` means the file passed preflight and is waiting for the user to start the import (6b). A preflight that crashes or times out becomes `failed` (the job's `failed()` hook).

## 1. Chunked, resumable upload (built in 6a)

- `POST /uploads {name, size}` checks the extension (`.sql`, `.sql.gz`, `.gz`, `.zip`), the size (`limits.max_upload_bytes`) and free disk (file size + `limits.min_free_disk_bytes`). It returns the chunk plan (`limits.upload_chunk_bytes`, 16 MB by default).
- The client (`useChunkedUpload`) sends `PUT /uploads/{id}/chunks/{index}` with the raw slice as the body (`application/octet-stream`, CSRF through `X-XSRF-TOKEN`). It runs 3 chunks in parallel and retries network errors, 5xx and 429 with exponential backoff (6 attempts).
- The server streams the body (`stream_copy_to_stream`) **straight to its byte offset in one file** (`{uploads}/{uuid}.upload`, mode 0600), then touches an empty marker `{uuid}.chunks/{index}`. A chunk of the wrong length isn't marked. Repeated or concurrent chunks are harmless, because they write the same bytes to the same place.
- **There's no assembly step:** once all markers exist, the upload is `uploaded` and the file is already complete. Disk use is 1× the file, and no long copy happens inside an HTTP request.
- `GET /uploads/{id}` returns the received indexes. The client remembers the upload id per file (name + size + mtime) in localStorage, so choosing the same file after a reload resumes the upload.
- The user's filename is metadata only. Paths are UUIDs. PHP `upload_max_filesize`/`post_max_size` and nginx `client_max_body_size` only need to exceed one chunk.
- Chunk PUTs are rate-limited to 600 per minute per user (`throttle:uploads`).

## 2. Source reader (built in 6a)

`App\Database\Import\SourceStream` gives `read(int $bytes): string`, `position()` and `total()` (in the same unit, for progress):

- `.sql` → `fopen(…, 'rb')`.
- `.sql.gz` → `fopen` + `inflate_add` in 8 KB compressed steps. Output per step is bounded even for gzip bombs. It handles multi-member files (concatenated gzip) and trailing zero padding. A file that ends mid-stream throws "truncated". Progress counts compressed bytes, so it compares with the file size.
- `.zip` → `ZipArchive::getStreamIndex()` on the single `.sql` entry. Nothing is extracted to disk. `__MACOSX/` and `._*` entries are ignored. The reader refuses: `..` segments, absolute or drive paths, NUL bytes, symlink entries, encrypted entries, and archives with ≠ 1 file. A short read throws "corrupt or truncated". Progress counts uncompressed bytes of the entry.

## 3. Preflight (`SqlStreamScanner`)

A tokenizer that streams the source and tracks state: inside a single/double/backtick quote, a `--`/`#` line comment, a `/* */` block comment, a `/*!40101 … */` conditional comment (treated as executable), and the current `DELIMITER`. It produces statement boundaries and the **first keyword(s)** of each statement. It never rewrites statement bodies.

Rules:

| Found                                                                                                                                                                    | Action                                                                                                                      |
| ------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------- |
| No `CREATE DATABASE` / `USE`                                                                                                                                             | OK — import into the selected target                                                                                        |
| References to exactly one database name (`CREATE DATABASE [IF NOT EXISTS] x`, `USE x`)                                                                                   | OK — those statements are **skipped** at the token level during import, and the target is pinned with `--database=<target>` |
| References to ≥ 2 distinct databases                                                                                                                                     | **Rejected** with a list of the names                                                                                       |
| `DROP DATABASE`                                                                                                                                                          | Rejected unless it targets the same single name (then skipped)                                                              |
| `ALTER DATABASE x` / `CREATE SCHEMA x` / `DROP SCHEMA x`                                                                                                                 | Count as references to `x`, same rules. `ALTER DATABASE` without a name (the default database) is not a reference           |
| A database-qualified table name in any table-name position (`INSERT INTO crm.t`, `DROP TABLE a, crm.b`, `CREATE INDEX i ON crm.t`, …)                                    | **Rejected**: it can't be redirected without rewriting the statement                                                        |
| A statement kind that dump tools don't write (UPDATE, DELETE, SELECT, CALL, RENAME, LOAD DATA, …)                                                                        | **Rejected** with its line: run such scripts in the SQL editor. See `MySQLDumpPolicy` and SECURITY.md                       |
| Text the mysql client would split differently (backslash client commands, delimiter inside `/*! */`, mid-statement `delimiter`, NO_BACKSLASH_ESCAPES, gbk-like charsets) | **Rejected**                                                                                                                |

**As built (6a):** `PreflightImportJob` (queue `imports`, `tries = 1`, 24 h timeout) streams the file once through `SourceStream` → `SqlStreamScanner` → `MySQLPreflightScan`. Only the **leading tokens** of each statement are read (`SqlStatement::leadingTokens()`), so a 1 MB INSERT costs almost nothing. The report (`PreflightReport`, stored as JSON on the import) has: problems (rejection reasons), warnings, referenced databases, the single source database, statement count, `CREATE TABLE` names (first 1,000) and count, skipped statement count, views/triggers/routines count, whether `FOREIGN_KEY_CHECKS=0` is set, the largest statement, and SQL bytes.

- **Also rejected:** a file that ends inside a string/identifier/block comment ("looks truncated"), a file with no statements, a statement larger than `limits.max_statement_bytes` (64 MB, which bounds worker memory), and unreadable/corrupt/unsafe sources.
- **Warnings (not rejected):** `DEFINER` clauses (need SET_USER_ID/SUPER unless the definer is you), `GTID_PURGED` (re-export with `--set-gtid-purged=OFF`), `LOAD DATA LOCAL` (it will fail because local files are disabled).
- **Conflicts:** the tables that already exist in the target are computed when the user views a `ready` import (the HTTP request holds the user's credentials; the preflight job needs none).
- Progress (`bytes_scanned`, `heartbeat_at`) is written every 2 s. The first chunk always writes, so `cancel_requested` is honoured immediately.
- Throughput on this machine is about 80–95 MB/s of SQL with +5 MB peak memory (plain or gzip), so about 2 minutes for 10 GB.

## 4. Import job

- `ImportDatabaseJob` runs on the `imports` queue with `tries = 1`, `timeout = 0` / very large, and the queue `retry_after` set above the max expected runtime (documented in INSTALL.md).
- `MySQLImporter`:
    1. Start `[mysql, --defaults-extra-file=…, --database=<target>, --default-character-set=<charset>, --batch, --show-warnings, --binary-mode, --local-infile=0]` with a Symfony `InputStream` as stdin. `--binary-mode` turns off client commands (`\!` shell, `source`, …) in piped input, and `--local-infile=0` stops a dump from reading files on the DBNova host.
    2. Pump loop: read 1 MB → split with the scanner → write each kept statement followed by its delimiter (re-emitting `DELIMITER x` when it changes; `MySQLImporter::skipsStatement()` drops the source database's statements) → write to stdin. Statement bodies are sent exactly as written.
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

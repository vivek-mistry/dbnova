# Performance

## Principles

1. Never load a whole table, result set, upload or dump into PHP memory.
2. Push work to MySQL (indexes, keyset pagination) and to the CLI (streaming).
3. Render only what's visible (virtualization).

## Table browsing

- **Keyset pagination** when a PK (or non-null unique key) exists: `WHERE (pk1, pk2) > (?, ?) ORDER BY pk1, pk2 LIMIT :n`. This costs about the same on any page.
- **OFFSET fallback** for tables without a key. The UI warns that deep pages get slower.
- **Counts:** `information_schema.TABLES.TABLE_ROWS` (approximate, instant) by default. `SELECT COUNT(*)` runs only on request.
- Page size defaults to 100 (max 1,000). The grid fetches the next page as you near the end of a virtualized scroll.
- Wide/long columns: TEXT/BLOB values are truncated server-side (`LEFT(col, 256)`) in the list, with the full value fetched on demand.

## Query execution

- Unbuffered cursor (`MYSQL_ATTR_USE_BUFFERED_QUERY=false`), stopping after `query_row_cap` rows (default 1,000) while reporting that there are more.
- `SET SESSION max_execution_time` for SELECT in the editor (configurable).

## Import / export

- 1 MB pump chunks into `mysql` stdin. Progress is written every ~8 MB or 2 s, not per chunk.
- Gzip is decompressed as a stream through `gzopen`, with no temp file.
- Exports: `mysqldump --quick --single-transaction` + incremental `deflate_add`.
- Worker memory target: ≤ 64 MB for any file size.

## Frontend

- `@tanstack/vue-virtual` for rows (and columns once there are more than 50).
- Inertia: deferred props for heavy panels, `useHttp` for grid paging and edits (no full visits), prefetch for the DB tree.
- Avoid deep reactive objects for row data (use `shallowRef`).

## Benchmarks (filled in during Phases 3, 6, 7, 9)

| Scenario                                                          | Target         | Measured                                                                        | Hardware              | Date       |
| ----------------------------------------------------------------- | -------------- | ------------------------------------------------------------------------------- | --------------------- | ---------- |
| Browse 10M rows, page 1                                           | ≤ 200 ms       | pending (needs `dbnova_test` user → `dbnova:stress:seed-table`)                 | —                     | —          |
| Browse 1.2M rows (`ecommerce.product_images`), page 1, 100 rows   | ≤ 200 ms       | 9.7 ms query                                                                    | dev Mac, MySQL 8.0.39 | 2026-10-30 |
| Same table, page 51 (keyset)                                      | ≤ 200 ms       | 0.2 ms query                                                                    | dev Mac               | 2026-10-30 |
| Same table, 1,000-row page as JSON                                | —              | 1.8 ms query, 192 KB JSON, 46.5 MB peak PHP memory                              | dev Mac               | 2026-10-30 |
| Same table sorted by a non-key column, OFFSET 100,000             | known slow     | 1,403 ms (offset fallback)                                                      | dev Mac               | 2026-10-30 |
| Browse 10M rows, page 50,000 (keyset)                             | ≤ 200 ms       | —                                                                               | —                     | —          |
| Scanner split, real `mysqldump` (353 MB, 1M rows)                 | —              | 103 MB/s, 9.5 MB peak (Phase 5 version: 16 MB/s)                                | dev Mac               | 2026-11-13 |
| Preflight 353 MB `.sql` / same as 25 MB `.sql.gz`                 | memory ≤ 64 MB | 4.6 s (77 MB/s) / 3.7 s (95 MB/s), +5 MB peak                                   | dev Mac               | 2026-11-13 |
| Preflight 2 GB synthetic dump (20.1M rows, 4 short strings/row)   | memory ≤ 64 MB | 37.3 s (55 MB/s), +4.3 MB peak                                                  | dev Mac               | 2026-11-13 |
| **Import 2 GB `.sql`** (20.1M rows, 4,038 INSERTs of ~0.5 MB)     | memory ≤ 64 MB | **117 s (17.6 MB/s, MySQL-bound), +6 MB peak, 30 MB process**; row counts exact | dev Mac, MySQL 8.0.39 | 2026-11-13 |
| Import 10 GB `.sql` (extrapolated from 2 GB; user chose 2 GB)     | memory ≤ 64 MB | ≈ 3 min preflight + ≈ 10 min import, memory flat                                | dev Mac               | 2026-11-13 |
| Import 10 GB `.sql.gz`                                            | memory ≤ 64 MB | —                                                                               | —                     | —          |
| **Export 10M-row table to `.sql.gz`** (3.7 GB SQL → 261 MB file)  | memory ≤ 64 MB | **26.4 s (141 MB/s of SQL)**, PHP 25 → 29 MB, flat                              | dev Mac, MySQL 8.0.39 | 2026-11-13 |
| **Export same to `.sql`** (3.7 GB file)                           | memory ≤ 64 MB | **61.8 s (60 MB/s, disk-write bound)**, PHP 25 → 27 MB, flat                    | dev Mac               | 2026-11-13 |
| Re-import of those exports through DBNova                         | exact          | `.sql.gz` 353 s / `.sql` 424 s; 10M rows and `CHECKSUM TABLE` identical         | dev Mac               | 2026-11-13 |
| Export 10 GB of SQL (extrapolated; user chose 2 GB + extrapolate) | memory ≤ 64 MB | ≈ 70 s `.sql.gz` / ≈ 3 min `.sql`, memory flat (output is never accumulated)    | dev Mac               | 2026-11-13 |

### Notes from Phase 3a

- Keyset paging is used when the table has a PK (or NOT NULL unique key) and the grid is unsorted or sorted by the first key column. Deep pages then cost the same as page 1.
- Sorting by any other column falls back to `LIMIT/OFFSET` with the key as a tiebreaker. Deep offsets on big tables get slow (1.4 s at offset 100k on 1.2M rows). The grid shows `keyset`/`offset` in its footer. Seek-paging on (sort column, key) is a possible later improvement.
- Only the key, preview text (`LEFT(col, 257)`) and binary length (`OCTET_LENGTH`) are fetched for large columns, so wide TEXT/BLOB tables stay small on the wire.
- Unindexed filters and sorts on 10M rows take seconds (MySQL has to scan or filesort). DBNova doesn't hide this. Follow-up for Phase 5: apply `max_execution_time` to browse queries and allow cancelling (`KILL QUERY`), so a slow sort can't hold a connection indefinitely.
- Stress data: `php artisan dbnova:stress:seed-table` (10M rows in about 7 minutes on the dev Mac, as the `dbnova_test` user, into `dbnova_test_stress.orders`).
- SQL editor: `SELECT * FROM orders` on 10M rows returns the first 1,000 rows in 11 ms with +2 MB of PHP memory. The rest of the result is stopped server-side with `KILL QUERY` instead of being streamed and discarded (Phase 5).
- Every DBNova connection sets `max_execution_time` (`limits.query_timeout_ms`, default 30 s), so runaway SELECTs (browse sorts, editor queries) are stopped by MySQL (Phase 5).

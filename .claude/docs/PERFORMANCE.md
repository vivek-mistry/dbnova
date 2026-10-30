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

| Scenario                              | Target         | Measured | Hardware | Date |
| ------------------------------------- | -------------- | -------- | -------- | ---- |
| Browse 10M rows, page 1               | ≤ 200 ms       | —        | —        | —    |
| Browse 10M rows, page 50,000 (keyset) | ≤ 200 ms       | —        | —        | —    |
| Import 10 GB `.sql`                   | memory ≤ 64 MB | —        | —        | —    |
| Import 10 GB `.sql.gz`                | memory ≤ 64 MB | —        | —        | —    |
| Export 5 GB DB `.sql.gz`              | memory ≤ 64 MB | —        | —        | —    |

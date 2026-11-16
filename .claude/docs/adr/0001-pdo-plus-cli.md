# ADR 0001 — Raw PDO for interactive access, MySQL CLI for bulk

**Status:** Accepted (2026-10-30)

## Context

The original spec said all managed-database work should go through the `mysql` CLI, and that Laravel's DB facade, Eloquent and Query Builder must not be used against the managed server. Interactive features (grid paging, inline edit, schema reads, SQL editor) run many small queries and need precise types.

## Decision

- **Interactive work** uses a raw `PDO` instance owned by `MySQLConnection` inside the adapter. It's not registered in `config/database.php` and isn't reachable through `DB::`.
- **Bulk work** (import, export) uses the `mysql` / `mysqldump` binaries through `ProcessRunner` (Symfony Process).

## Alternatives

1. **CLI only.** `mysql --batch` prints NULL and the string `'NULL'` the same way, loses column types, and starts a process (~20–50 ms) on every request. `--xml` fixes NULLs but is verbose to parse and still slow. Cancelling and unbuffered cursors are awkward. Rejected.
2. **A Laravel DB connection (`DB::connection('managed')`).** Convenient, but it mixes the managed server into app config, encourages Eloquent/Query Builder use, and makes it easy to point app migrations at the wrong server. Rejected, as in the original spec.
3. **PDO for everything, including import.** PHP would have to parse and split SQL statements for multi-GB files. That's slower and riskier than the battle-tested `mysql` client. Rejected.

## Consequences

- Needs the `pdo_mysql` extension **and** the CLI binaries.
- Two code paths per adapter, both hidden behind contracts.
- Adapter integration tests need a real MySQL server.

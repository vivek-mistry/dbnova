# Phase 2 — Adapter layer & database explorer

**Status:** done
**Goal:** The adapter contracts + MySQL implementation, and a working database explorer (list, create, drop, rename) with live dashboard stats.
**New dependencies:** none (Symfony Process ships with Laravel).
**Read first:** `docs/DATABASE_ADAPTERS.md`, `docs/adr/0001-pdo-plus-cli.md`, `docs/adr/0008-mysql-account-login.md`, the `dbnova-adapter` skill.

## Tasks

### Adapter foundation (`app/Database/`)

- [x] `Contracts/`: `DatabaseAdapterInterface` (`engine`, `server`, `schema`), `ServerInspectorInterface`, `SchemaManagerInterface`. Each later phase adds its own contract (changed from "declare everything now")
- [x] `Support/IdentifierQuoter`, `Support/ReadOnlyGuard`
- [x] `Processes/ProcessRunner` (explicit credentials, array args, Laravel `ProcessResult`) and `DefaultsFile` (0600, escaped values, deleted in `finally`). There's no separate `ProcessResult` class
- [x] `Adapters/MySQL/MySQLConnection`: lazy PDO through `MySQLConnector` + the session's `MySQLCredentialStore`, native prepares, `SET NAMES`, `information_schema_stats_expiry = 0`, plus `select`/`scalar`/`statement` helpers. It's a scoped binding (one per request or job)
- [x] `Adapters/MySQL/MySQLAdapter`, `MySQLServerInspector`, `MySQLSchemaManager`
- [x] `DTOs/ServerInfo`, `DatabaseInfo`, `TableInfo`, `CollationInfo`
- [x] `Exceptions/DatabaseOperationException` (friendly MySQL code mapping), `ReadOnlyModeException`, `InvalidIdentifierException`, `NotSignedInException`
- [x] Bind `DatabaseAdapterInterface` in `AppServiceProvider`
- [x] Phase 1's reachability check stays on `MySQLConnector`: it must work without credentials (CLI), which the adapter needs. The architecture test allows this one class

### Audit log

- [x] `AuditLog` model + migration + factory, and an `AuditLogger::track()` that records success or failure (with the error) and rethrows

### Explorer features

- [x] `DatabaseController`:
    - index: databases + deferred collations
    - store: name + optional collation, validated against the server
    - show: tables + rename blockers
    - update: rename
    - destroy: typed name + MySQL password re-check in the same dialog
- [x] Rename: create + one atomic `RENAME TABLE`, then drop the old database. It's refused when views, routines, events or triggers exist, and the new database is cleaned up if the move fails
- [x] `DatabaseName` rule: ≤ 64 characters, no `/ \ .` or NUL, no trailing space, not a system database
- [x] `DatabaseOperationException` renderer: Inertia `DatabaseError` page or JSON 503
- [x] Sidebar database tree (`DatabaseTree.vue`, `DatabaseTreeItem.vue`): lazy tables through `useHttp`, a filter, and a refresh after database pages change
- [x] `pages/databases/Index.vue` (filter, sizes, system badge, create dialog), `pages/databases/Show.vue` (tables, rename/drop dialogs, disabled in read-only mode)
- [x] Dashboard shows live `ServerInfo` (deferred prop + skeleton + error message)

### Tests & CI

- [x] Unit: `IdentifierQuoter`, `DatabaseOperationException`, `DatabaseName`, `ArchitectureTest`
- [x] Feature:
    - `DefaultsFile` (0600, escaping, socket, delete)
    - `ProcessRunner` (no password in argv, file removed)
    - `MySQLSchemaManager` with a mocked connection (read-only, quoting, collation check, system DBs, atomic rename, blockers, cleanup)
- [x] Feature (mocked adapter): `DatabaseControllerTest`, `DatabaseTreeControllerTest`, `DashboardTest`
- [x] Integration (`DBNOVA_TEST_DB_USERNAME`): create/list/drop, rename moves rows, a view blocks the rename, server info. **Skipped locally until the test user exists**
- [x] Architecture test (Pest `arch()`)
- [x] MySQL 8.0 service in `.github/workflows/tests.yml`

## Acceptance criteria

- The dashboard shows real server version, uptime, database count and total size. ✅ The adapter was run against the local server: 8.0.39, 69 databases, 13.9 GB. `DashboardTest` covers the deferred prop and the error state.
- Databases can be created, renamed and dropped from the UI, and each action is audited. ✅ Feature tests and the mocked-connection SQL tests. ⏳ Real-MySQL integration tests are waiting on the test user, and the browser check is still pending.
- Read-only mode blocks mutations at the adapter level. ✅ `MySQLSchemaManagerTest` (no statement runs).
- The architecture test proves there's no DB facade use against the managed server. ✅ `ArchitectureTest`.
- `composer ci:check`: ✅ lint, format, vue-tsc, Pint, PHPStan level 7 (0 errors), Pest 114 passed / 4 skipped (integration).

## Completion notes

- **Security review:** the `dbnova-security-reviewer` agent failed with a network error, so the review was done by hand against `dbnova-security-review`:
    - Every identifier goes through `IdentifierQuoter`, and values are bound.
    - Collations are regex-validated in the request and checked against `information_schema` before use.
    - Read-only mode is enforced in all three mutating methods.
    - Error messages carry MySQL text (user@host, never the password).
    - There's no `v-html`.
    - **Fixed:** `DropDatabaseRequest` now refuses, rather than skips, the password check when there's no `User`.
    - Rerun the agent review when the network is available.
- **Incident:** a tinker sanity check of `AuditLogFactory::make()` created a fake user in the dev SQLite DB. It was deleted immediately and had no audit rows. Recorded in gotchas.
- **Follow-ups:**
    - Phase 3 adds `browser()` to the adapter and links tree tables to the table browser.
    - Table entries in the sidebar tree aren't links yet.

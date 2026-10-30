# Phase 2 — Adapter layer & database explorer

**Status:** not started
**Goal:** The adapter contracts + MySQL implementation, and a working database explorer (list, create, drop, rename) with live dashboard stats.
**New dependencies:** none (Symfony Process ships with Laravel).
**Read first:** `docs/DATABASE_ADAPTERS.md`, `docs/adr/0001-pdo-plus-cli.md`, the `dbnova-adapter` skill.

## Tasks

### Adapter foundation (`app/Database/`)
- [ ] `Contracts/*` interfaces (see DATABASE_ADAPTERS.md). For now, only the methods used in this phase get implementations, and the rest are declared
- [ ] `Support/IdentifierQuoter`, `Support/ReadOnlyGuard`
- [ ] `Processes/ProcessRunner`, `ProcessResult`, `DefaultsFile`
- [ ] `Adapters/MySQL/MySQLConnection` (lazy PDO, options per the doc, `useDatabase()`)
- [ ] `Adapters/MySQL/MySQLAdapter`, `MySQLServerInspector`, `MySQLSchemaManager`
- [ ] `DTOs/ServerInfo`, `DatabaseInfo`, `TableInfo`
- [ ] `Exceptions/DatabaseOperationException` with friendly message mapping
- [ ] Bind `DatabaseAdapterInterface` in `AppServiceProvider`
- [ ] Refactor Phase 1's MySQL reachability check to use the adapter

### Audit log (moved earlier from Phase 8)
- [ ] `AuditLog` model + migration + factory, plus an `AuditLogger` service

### Explorer features
- [ ] `DatabaseController`: index (list with size, table count, collation), store (create with charset/collation), destroy (typed confirmation + password confirm), rename (create + `RENAME TABLE` loop)
- [ ] Sidebar database tree: lazy-load tables per database (`useHttp`), with a filter box
- [ ] `pages/databases/Index.vue`, `pages/databases/Show.vue` (table list with rows, size, engine, collation)
- [ ] Dashboard shows live `ServerInfo` (deferred prop + skeleton)

### Tests & CI
- [ ] Unit: `IdentifierQuoter`, `DefaultsFile` (0600, deleted afterwards), `ReadOnlyGuard`
- [ ] Feature (fake adapter): controllers, validation, read-only blocks create/drop, audit entries written
- [ ] Integration (`DBNOVA_TEST_MYSQL=1`): create/list/drop `dbnova_test_*`
- [ ] Architecture test (Pest `arch()`): `App\Http` and `App\Services` don't use `MySQL*` classes, and `App\Database` doesn't use `Illuminate\Support\Facades\DB`
- [ ] Add a MySQL service to `.github/workflows/tests.yml`

## Acceptance criteria
- The dashboard shows real server version, uptime, database count and total size.
- Databases can be created, renamed and dropped from the UI, and each action is audited.
- Read-only mode blocks mutations at the adapter level.
- The architecture test proves there's no DB facade use against the managed server.

## Completion notes
_(filled by /phase-done)_

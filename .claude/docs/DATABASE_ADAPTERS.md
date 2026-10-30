# Database Adapters

## Goal

All managed-database work goes through engine-neutral contracts. MySQL is the first implementation. Adding MariaDB or PostgreSQL later should mean adding a new folder under `app/Database/Adapters/` without touching controllers.

## Contracts (`app/Database/Contracts`)

| Interface                  | Responsibility                 | Key methods (indicative)                                                                                                                                                                                                            |
| -------------------------- | ------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `DatabaseAdapterInterface` | Entry point and factory        | `engine(): string`, `server()`, `schema()` (Phase 2). Each later phase adds its accessor: `browser()` (3), `tables()` (4), `queries()` (5), `importer()` (6), `exporter()` (7), plus `capabilities()` once a second engine needs it |
| `ServerInspectorInterface` | Server metadata                | `info(): ServerInfo`, `status()`, `variables()`, `processes()`, `killQuery(int)`                                                                                                                                                    |
| `SchemaManagerInterface`   | Databases and catalog          | `databases(): list<DatabaseInfo>`, `createDatabase()`, `dropDatabase()`, `tables(db)`, `columns(db, table)`, `indexes()`, `foreignKeys()`                                                                                           |
| `TableManagerInterface`    | DDL                            | `create()`, `alter(TableChange[])`, `drop()`, `truncate()`, `rename()`, `previewSql(...)`                                                                                                                                           |
| `TableBrowserInterface`    | Read access to rows (Phase 3a) | `columns()`, `keyColumns()`, `indexes()`, `createStatement()`, `page(TableRef, PageRequest): Page`, `count(filters)`, `value(table, column, keyValues)`. Writes (`insert`/`update`/`delete`) come in Phase 3b                       |
| `QueryExecutorInterface`   | SQL editor                     | `execute(db, sql, QueryOptions): QueryResultSet`, `cancel(connectionId)`                                                                                                                                                            |
| `ImporterInterface`        | Bulk import                    | `preflight(Source, target): PreflightReport`, `import(Source, target, ProgressReporter): ImportOutcome`                                                                                                                             |
| `ExporterInterface`        | Bulk export                    | `exportDatabase(db, tables, ExportOptions, sink, ProgressReporter)`, `exportCsv(...)`                                                                                                                                               |

`Capabilities` lists engine features (for example `supportsKeysetPagination`, `supportsTransactionalDdl=false` for MySQL), so the UI can hide unsupported actions.

## MySQL implementation (`app/Database/Adapters/MySQL`)

- `MySQLConnection` creates PDO lazily from `config('dbnova.connection')` with these settings:
    - `ERRMODE_EXCEPTION`, `ATTR_EMULATE_PREPARES=false`, `ATTR_STRINGIFY_FETCHES=false`, `MYSQL_ATTR_INIT_COMMAND="SET NAMES utf8mb4"`.
    - It's one connection per request, and database context is set with a quoted `USE`.
    - `MYSQL_ATTR_USE_BUFFERED_QUERY=false` is used for export/CSV/large-result cursors.
    - There's a second, short-lived connection for `KILL QUERY`.
- `MySQLServerInspector`, `MySQLSchemaManager`: read from `information_schema` and `performance_schema` (when available) using bound parameters.
- `MySQLTableBrowser`: keyset pagination when a PK exists, `LIMIT/OFFSET` otherwise (with a warning on deep pages). The approximate count comes from `information_schema.TABLES.TABLE_ROWS`.
- `MySQLImporter` / `MySQLExporter`: use `ProcessRunner` with the `mysql` / `mysqldump` binaries.

## Process layer (`app/Database/Processes`)

- `ProcessRunner::run('mysql'|'mysqldump', array $credentials, list<string> $arguments, ?int $timeoutSeconds): ProcessResult` (Laravel's process result). Credentials are passed explicitly so queued jobs can supply the ones stored on their job row (ADR 0008). Phase 6 adds streaming stdin.
- `DefaultsFile`: writes `[client] user/password/host/port/socket` to a temp file with mode 0600, and `--defaults-extra-file=` is passed **first** in argv. The file is deleted in `finally`.
- Process arguments are always arrays.

## Support

- `IdentifierQuoter::quote('my`db') → "`my``db`"`. It rejects empty names, NUL bytes and names over 64 chars.
- `ReadOnlyGuard::assertWritable()` is called by every mutating adapter method.

## Adding a new engine (future)

1. Create `Adapters/<Engine>/` implementing every contract (throw `UnsupportedOperation` where `Capabilities` says so).
2. Add engine-specific quoting (`"ident"` for PostgreSQL), binaries (`psql`, `pg_dump`) and a scanner dialect.
3. Bind it through config `dbnova.engine`.
4. Run the shared contract test suite (`tests/Feature/Database/Contracts/*`) against the new adapter.

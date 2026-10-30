# DBNova — Architecture

## Layers

```
┌───────────────────────────────────────────────────────────────┐
│ Vue 3 + Inertia v3 (TypeScript)                               │
│ Dashboard · DB tree · Data grid · SQL editor · Import/Export  │
└──────────────────────────────┬────────────────────────────────┘
                               │ Inertia visits / useHttp (JSON)
┌──────────────────────────────▼────────────────────────────────┐
│ Laravel HTTP: Controllers · Form Requests · Policies/Auth     │
├───────────────────────────────────────────────────────────────┤
│ Application services: app/Services/{Database,Import,Export,   │
│ System} — orchestration, audit log, read-only enforcement     │
├───────────────────────────────────────────────────────────────┤
│ Adapter contracts: app/Database/Contracts                     │
│   └── MySQL adapter: app/Database/Adapters/MySQL              │
│         ├── PDO (interactive)      ├── ProcessRunner (bulk)   │
└─────────┬──────────────────────────┴───────────┬──────────────┘
          │ pdo_mysql                            │ mysql / mysqldump (Symfony Process)
┌─────────▼──────────────────────────────────────▼──────────────┐
│ Local MySQL server (managed databases)                        │
└───────────────────────────────────────────────────────────────┘

Separate: SQLite (database/database.sqlite) ← users, sessions, cache, queue jobs,
uploads, import/export jobs, query history, audit log. Accessed with Eloquent as usual.

Queue worker (php artisan queue:work --queue=imports,exports,default) runs
ImportDatabaseJob / ExportDatabaseJob in the background.
```

## Directory layout (target)

```
app/
├── Database/                    # everything that talks to the managed server
│   ├── Contracts/               # DatabaseAdapterInterface, ServerInspectorInterface,
│   │                            # SchemaManagerInterface, TableBrowserInterface,
│   │                            # TableManagerInterface, QueryExecutorInterface,
│   │                            # ImporterInterface, ExporterInterface
│   ├── Adapters/MySQL/          # MySQLAdapter, MySQLConnection, MySQLServerInspector,
│   │                            # MySQLSchemaManager, MySQLTableBrowser, MySQLTableManager,
│   │                            # MySQLQueryExecutor, MySQLImporter, MySQLExporter
│   ├── Processes/               # ProcessRunner, ProcessResult, DefaultsFile
│   ├── Sql/                     # SqlStreamScanner (tokenizer shared by editor + import)
│   ├── Support/                 # IdentifierQuoter, ReadOnlyGuard
│   ├── Exceptions/              # DatabaseOperationException, ReadOnlyModeException…
│   └── DTOs/                    # readonly value objects (DatabaseInfo, TableInfo, Page…)
├── Http/Controllers/            # DashboardController, DatabaseController, TableController,
│                                # RecordController, StructureController, QueryController,
│                                # UploadController, ImportController, ExportController,
│                                # ProcessController, SystemController
├── Jobs/                        # ImportDatabaseJob, ExportDatabaseJob
├── Services/{Database,Import,Export,System}/
├── Models/                      # User, Upload, ImportJob, ExportJob, QueryHistory, AuditLog
└── Console/Commands/            # dbnova:install, dbnova:recover-jobs, dbnova:prune-temp

resources/js/
├── pages/{dashboard,databases,tables,sql,import-export,processes,system}/
├── components/{database,table,editor,data-grid,import-export}/   # PascalCase .vue
├── composables/  (useChunkedUpload, useDataGrid, useJobProgress…)
└── types/        (database.ts, import.ts…)
```

## Decisions (summary — details and alternatives are in `adr/`)

| #    | Decision                                                      | Alternatives considered                                       | Why                                                                                                                                                                                               |
| ---- | ------------------------------------------------------------- | ------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 0001 | Raw PDO for interactive work, CLI for bulk                    | CLI-only. Laravel DB connection                               | Keeps types and exact NULLs, unbuffered cursors, no process spawn per request. The CLI streams huge dumps best. A Laravel connection would tie the managed server to app config and invite misuse |
| 0002 | SQLite for app metadata                                       | Store metadata in the managed MySQL. Separate MySQL schema    | Must survive when the managed server is broken or being re-imported. Zero setup                                                                                                                   |
| 0003 | Database queue driver                                         | Redis (spec default). Sync                                    | No extra service or package. A single operator doesn't need Redis throughput. Redis stays an `.env` switch                                                                                        |
| 0008 | Sign in with a MySQL account (supersedes 0004)                | App admin + creds in `.env`. Custom guard without users table | MySQL grants decide access, like phpMyAdmin. Fortify login/throttling reused                                                                                                                      |
| 0005 | Polling for progress                                          | Reverb / websockets. SSE                                      | No extra daemon. Inertia v3 `usePoll` is built in                                                                                                                                                 |
| 0006 | Token-aware streaming import with single-database enforcement | Regex rewriting. Importing blindly                            | Regex breaks on strings and comments. Blind imports can write to the wrong DB                                                                                                                     |
| 0007 | Committed `.claude/` workspace                                | Docs in `docs/` only                                          | Shared Claude context, skills and phase workflow for every session                                                                                                                                |

## Request flows

**Browse table:** `TableController@show` → `TableBrowser::page(db, table, cursor, sort, filters)` → PDO prepared `SELECT … WHERE (pk) > ? ORDER BY pk LIMIT n` → `Page` DTO → Inertia props (the first page) or JSON via `useHttp` (later pages).

**Import:** chunked upload → `Upload` assembled → preflight (`SqlStreamScanner`) → `ImportJob` row → `ImportDatabaseJob` → `MySQLImporter` pumps a stream into `mysql` stdin → the job row is updated with progress and heartbeat → the UI polls. Full detail is in `IMPORT_EXPORT_ENGINE.md`.

**Export:** `ExportDatabaseJob` → `mysqldump` stdout is streamed (optionally gzipped) to a temp file → signed, expiring download.

## Configuration (`config/dbnova.php`, Phase 1)

```
connection: host, port, socket, charset (utf8mb4), connect_timeout   # no credentials: users sign in with MySQL accounts
binaries: mysql, mysqldump
paths: uploads, imports, exports (under storage/app/dbnova)
limits: upload_chunk_bytes, pump_chunk_bytes, query_row_cap, query_timeout_ms, page_size
retention: failed_import_hours, export_link_minutes
read_only: bool
```

## Error handling & logging

- A dedicated `dbnova` log channel. MySQL errors are wrapped in `DatabaseOperationException` (SQLSTATE, driver code, message) and mapped to friendly UI messages.
- Credentials are never put in exceptions, logs or job payloads.

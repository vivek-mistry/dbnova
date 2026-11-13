<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Managed MySQL Server
    |--------------------------------------------------------------------------
    |
    | Where the MySQL server that DBNova manages lives. This is NOT a Laravel
    | database connection: it is only read by the DBNova adapter layer. No
    | username or password is configured here; users sign in to DBNova with
    | their own MySQL account.
    |
    */

    'connection' => [
        'host' => env('DBNOVA_DB_HOST', '127.0.0.1'),
        'port' => (int) env('DBNOVA_DB_PORT', 3306),
        'socket' => env('DBNOVA_DB_SOCKET'),
        'charset' => env('DBNOVA_DB_CHARSET', 'utf8mb4'),
        'connect_timeout' => (int) env('DBNOVA_DB_CONNECT_TIMEOUT', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Client Binaries
    |--------------------------------------------------------------------------
    |
    | Absolute paths to the MySQL client tools. Shell aliases are invisible
    | to PHP processes, so these must point at real executable files.
    |
    */

    'binaries' => [
        'mysql' => env('DBNOVA_MYSQL_BIN', '/usr/bin/mysql'),
        'mysqldump' => env('DBNOVA_MYSQLDUMP_BIN', '/usr/bin/mysqldump'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Working Directories
    |--------------------------------------------------------------------------
    */

    'paths' => [
        'uploads' => storage_path('app/dbnova/uploads'),
        'imports' => storage_path('app/dbnova/imports'),
        'exports' => storage_path('app/dbnova/exports'),
        'tmp' => storage_path('app/dbnova/tmp'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    */

    'limits' => [
        'upload_chunk_bytes' => (int) env('DBNOVA_UPLOAD_CHUNK_BYTES', 16 * 1024 * 1024),
        'pump_chunk_bytes' => (int) env('DBNOVA_PUMP_CHUNK_BYTES', 1024 * 1024),
        'max_upload_bytes' => (int) env('DBNOVA_MAX_UPLOAD_BYTES', 100 * 1024 * 1024 * 1024),
        // One statement in an imported file may not exceed this (mysqldump writes about 1 MB per INSERT).
        'max_statement_bytes' => (int) env('DBNOVA_MAX_STATEMENT_BYTES', 64 * 1024 * 1024),
        'page_size' => (int) env('DBNOVA_PAGE_SIZE', 100),
        'query_row_cap' => (int) env('DBNOVA_QUERY_ROW_CAP', 1000),
        'query_timeout_ms' => (int) env('DBNOVA_QUERY_TIMEOUT_MS', 30000),
        'min_free_disk_bytes' => (int) env('DBNOVA_MIN_FREE_DISK_BYTES', 5 * 1024 * 1024 * 1024),
        // A schema change that would copy (and lock) a table with more rows than this needs explicit confirmation.
        'locking_ddl_confirm_rows' => (int) env('DBNOVA_LOCKING_DDL_CONFIRM_ROWS', 1_000_000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    */

    'retention' => [
        'failed_import_hours' => (int) env('DBNOVA_FAILED_IMPORT_HOURS', 24),
        'incomplete_upload_hours' => (int) env('DBNOVA_INCOMPLETE_UPLOAD_HOURS', 24),
        'export_link_minutes' => (int) env('DBNOVA_EXPORT_LINK_MINUTES', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Heartbeat
    |--------------------------------------------------------------------------
    |
    | The scheduler queues a heartbeat job every minute. When the worker has
    | not processed one within this many seconds it is reported as down.
    |
    */

    'queue_heartbeat_stale_seconds' => (int) env('DBNOVA_QUEUE_HEARTBEAT_STALE_SECONDS', 180),

    /*
    |--------------------------------------------------------------------------
    | Read-only Mode
    |--------------------------------------------------------------------------
    |
    | When enabled, every write operation against the managed server is
    | refused by the adapter layer.
    |
    */

    'read_only' => (bool) env('DBNOVA_READ_ONLY', false),

    /*
    |--------------------------------------------------------------------------
    | Test Account
    |--------------------------------------------------------------------------
    |
    | A MySQL account limited to `dbnova\_test\_%` databases, used by the
    | integration tests and the stress-data commands. Leave empty to skip them.
    |
    */

    'testing' => [
        'username' => env('DBNOVA_TEST_DB_USERNAME'),
        'password' => env('DBNOVA_TEST_DB_PASSWORD', ''),
    ],

];

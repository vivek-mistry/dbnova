<?php

use App\Database\Adapters\MySQL\MySQLConnection;
use App\Database\Adapters\MySQL\MySQLExporter;
use App\Database\Adapters\MySQL\MySQLImporter;
use App\Database\DTOs\Export\ExportRequest;
use App\Database\DTOs\Export\ExportResult;
use App\Database\DTOs\Import\ImportRequest;
use App\Database\Exceptions\DatabaseOperationException;
use App\Database\Export\ExportCancelledException;
use App\Database\Import\SourceStream;
use App\Enums\ExportFormat;

/**
 * Runs real exports through mysqldump, and re-imports them through the
 * Phase 6 importer, when DBNOVA_TEST_DB_USERNAME and executable
 * DBNOVA_MYSQLDUMP_BIN / DBNOVA_MYSQL_BIN are set.
 */
beforeEach(function () {
    $this->credentials = mySqlTestCredentials();

    if ($this->credentials === null) {
        $this->markTestSkipped('Set DBNOVA_TEST_DB_USERNAME in .env to run MySQL integration tests.');
    }

    if (! is_executable(config()->string('dbnova.binaries.mysqldump')) || ! is_executable(config()->string('dbnova.binaries.mysql'))) {
        $this->markTestSkipped('Set DBNOVA_MYSQLDUMP_BIN and DBNOVA_MYSQL_BIN to run export tests.');
    }

    $this->connection = app(MySQLConnection::class)->useCredentials($this->credentials);
    $this->source = 'dbnova_test_'.bin2hex(random_bytes(4));
    $this->target = 'dbnova_test_'.bin2hex(random_bytes(4));
    $this->connection->statement("CREATE DATABASE `{$this->source}`");
    $this->connection->statement("CREATE DATABASE `{$this->target}`");
    $this->directory = sys_get_temp_dir().'/dbnova-export-'.bin2hex(random_bytes(4));
    mkdir($this->directory);

    $source = $this->source;
    $this->connection->statement("CREATE TABLE `{$source}`.customers (id int PRIMARY KEY, name varchar(50), avatar varbinary(16), note text NULL)");
    $this->connection->statement("CREATE TABLE `{$source}`.orders (id int PRIMARY KEY, customer_id int, total decimal(10,2))");
    $this->connection->statement(
        "INSERT INTO `{$source}`.customers VALUES (1, 'Ada; Lovelace', 0x00FF0A0D27, NULL), (2, 'O''Brien', NULL, 'line\\nbreak'), (3, 'Zoë 🚀', '', '-- not a comment; USE other;')",
    );
    $this->connection->statement("INSERT INTO `{$source}`.orders VALUES (1, 1, 10.50), (2, 1, 3.25), (3, 3, 99.99)");
    $this->connection->statement("CREATE VIEW `{$source}`.big_orders AS SELECT id, total FROM `{$source}`.orders WHERE total > 5");
    $this->connection->statement("CREATE PROCEDURE `{$source}`.order_count() BEGIN SELECT COUNT(*) FROM orders; SELECT 1; END");

    $this->export = function (array $options = [], ?callable $onProgress = null): ExportResult {
        return app(MySQLExporter::class)->export(
            new ExportRequest($this->source, $this->credentials, $options['path'] ?? $this->directory.'/dump.sql', ...array_diff_key($options, ['path' => true])),
            $onProgress ?? fn () => null,
        );
    };

    $this->checksum = fn (string $database, string $table): string => (string) ($this->connection->select("CHECKSUM TABLE `{$database}`.`{$table}`")[0]['Checksum'] ?? '');
})->group('mysql');

afterEach(function () {
    if (isset($this->connection, $this->source)) {
        $this->connection->statement("DROP DATABASE IF EXISTS `{$this->source}`");
        $this->connection->statement("DROP DATABASE IF EXISTS `{$this->target}`");
    }

    if (isset($this->directory)) {
        array_map('unlink', glob($this->directory.'/*') ?: []);
        rmdir($this->directory);
    }
});

test('a database export re-imports through DBNova into another database with identical data and schema', function (ExportFormat $format, string $sourceFormat) {
    $path = $this->directory.'/dump.'.$format->extension();
    $result = ($this->export)(['path' => $path, 'format' => $format]);

    expect($result->succeeded)->toBeTrue()
        ->and($result->bytesWritten)->toBeGreaterThan(0)
        ->and($result->fileBytes)->toBe(filesize($path));

    $importer = app(MySQLImporter::class);
    $report = $importer->preflight(SourceStream::open($path, $sourceFormat));

    expect($report->accepted())->toBeTrue(implode(' ', $report->problems))
        ->and($report->databases)->toBe([])
        ->and($report->compoundProgramCount)->toBe(1);

    $import = $importer->import(
        new ImportRequest($this->target, $this->credentials, trustCompoundPrograms: true),
        SourceStream::open($path, $sourceFormat),
        fn () => null,
    );

    expect($import->succeeded)->toBeTrue($import->error ?? '');

    foreach (['customers', 'orders'] as $table) {
        expect(($this->checksum)($this->target, $table))->toBe(($this->checksum)($this->source, $table));
    }

    $objects = fn (string $database): array => array_column($this->connection->select(
        'SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME',
        [$database],
    ), 'TABLE_TYPE', 'TABLE_NAME');

    expect($objects($this->target))->toBe($objects($this->source));
    expect($this->connection->scalar('SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ?', [$this->target]))->toBe(1);
    expect($this->connection->scalar("SELECT name FROM `{$this->target}`.customers WHERE id = 3"))->toBe('Zoë 🚀');
})->with([
    'sql' => [ExportFormat::Sql, 'sql'],
    'sql.gz' => [ExportFormat::SqlGz, 'gz'],
]);

test('the dump names no database, so it imports anywhere', function () {
    ($this->export)();
    $sql = (string) file_get_contents($this->directory.'/dump.sql');

    expect($sql)->not->toContain('CREATE DATABASE')
        ->not->toMatch('/^USE /m')
        ->not->toContain($this->source.'`.')
        ->not->toContain('GTID_PURGED')
        ->toContain('-- Dump completed');
});

test('a table selection, structure only and data only dump just what was asked', function () {
    ($this->export)(['tables' => ['orders']]);
    $tables = (string) file_get_contents($this->directory.'/dump.sql');

    expect($tables)->toContain('CREATE TABLE `orders`')->not->toContain('CREATE TABLE `customers`');

    ($this->export)(['path' => $this->directory.'/structure.sql', 'content' => 'structure']);
    $structure = (string) file_get_contents($this->directory.'/structure.sql');

    expect($structure)->toContain('CREATE TABLE `customers`')->not->toContain('INSERT INTO');

    ($this->export)(['path' => $this->directory.'/data.sql', 'content' => 'data']);
    $data = (string) file_get_contents($this->directory.'/data.sql');

    expect($data)->toContain('INSERT INTO `customers`')->not->toContain('CREATE TABLE')->not->toContain('PROCEDURE');
});

test('routines can be left out', function () {
    ($this->export)(['includePrograms' => false]);

    expect((string) file_get_contents($this->directory.'/dump.sql'))->not->toContain('PROCEDURE');
});

test('a missing table is reported before mysqldump runs, and no file is left', function () {
    expect(fn () => ($this->export)(['tables' => ['orders', 'nope']]))
        ->toThrow(DatabaseOperationException::class, 'nope');

    expect(file_exists($this->directory.'/dump.sql'))->toBeFalse();
});

test('a cancel stops mysqldump and deletes the partial file', function () {
    expect(fn () => ($this->export)([], fn () => throw new ExportCancelledException))
        ->toThrow(ExportCancelledException::class);

    expect(file_exists($this->directory.'/dump.sql'))->toBeFalse();
});

test('an existing file is never overwritten', function () {
    file_put_contents($this->directory.'/dump.sql', 'keep me');

    expect(fn () => ($this->export)())->toThrow(DatabaseOperationException::class);
    expect(file_get_contents($this->directory.'/dump.sql'))->toBe('keep me');
});

test('read-only mode still allows exports', function () {
    config(['dbnova.read_only' => true]);

    expect(($this->export)()->succeeded)->toBeTrue();
});

test('the size estimate comes from the catalog', function () {
    $estimate = app(MySQLExporter::class)->estimateBytes($this->source);

    expect($estimate)->toBeGreaterThan(0)
        ->and(app(MySQLExporter::class)->estimateBytes($this->source, ['orders']))->toBeLessThanOrEqual($estimate);
});

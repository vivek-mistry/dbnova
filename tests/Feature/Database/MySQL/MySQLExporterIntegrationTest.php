<?php

use App\Database\Adapters\MySQL\MySQLConnection;
use App\Database\Adapters\MySQL\MySQLExporter;
use App\Database\Adapters\MySQL\MySQLImporter;
use App\Database\DTOs\Export\CsvExportRequest;
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

describe('CSV', function () {
    beforeEach(function () {
        $this->csv = function (array $options = [], ?callable $onProgress = null, ?callable $onStart = null): ExportResult {
            return app(MySQLExporter::class)->exportCsv(
                new CsvExportRequest(
                    $this->credentials,
                    $options['path'] ?? $this->directory.'/out.csv',
                    $options['format'] ?? ExportFormat::Csv,
                    $options['database'] ?? $this->source,
                    $options['table'] ?? null,
                    $options['query'] ?? null,
                ),
                $onProgress ?? fn () => null,
                $onStart,
            );
        };
    });

    test('a table is written as RFC 4180 CSV that keeps NULL, empty strings, quotes, newlines, unicode and binary apart', function () {
        $result = ($this->csv)(['table' => 'customers']);

        expect($result->succeeded)->toBeTrue()->and($result->rowsWritten)->toBe(3);
        expect(file_get_contents($this->directory.'/out.csv'))->toBe(
            "\"id\",\"name\",\"avatar\",\"note\"\r\n"
            ."1,\"Ada; Lovelace\",\"0x00ff0a0d27\",\r\n"
            ."2,\"O'Brien\",,\"line\nbreak\"\r\n"
            ."3,\"Zoë 🚀\",\"\",\"-- not a comment; USE other;\"\r\n",
        );
    });

    test('a read-only query from the SQL editor is exported with all its rows, gzip-compressed if asked', function () {
        $result = ($this->csv)([
            'query' => 'SELECT c.name, SUM(o.total) AS total FROM customers c JOIN orders o ON o.customer_id = c.id GROUP BY c.name ORDER BY c.name',
            'format' => ExportFormat::CsvGz,
            'path' => $this->directory.'/out.csv.gz',
        ]);

        expect($result->succeeded)->toBeTrue()->and($result->rowsWritten)->toBe(2);
        expect(gzdecode((string) file_get_contents($this->directory.'/out.csv.gz')))
            ->toBe("\"name\",\"total\"\r\n\"Ada; Lovelace\",\"13.75\"\r\n\"Zoë 🚀\",\"99.99\"\r\n");
    });

    test('only a single read-only query is accepted, and nothing is written for a refused one', function (string $query) {
        expect(fn () => ($this->csv)(['query' => $query]))->toThrow(DatabaseOperationException::class);

        expect(file_exists($this->directory.'/out.csv'))->toBeFalse()
            ->and(($this->checksum)($this->source, 'orders'))->not->toBe('');
        expect($this->connection->scalar("SELECT COUNT(*) FROM `{$this->source}`.orders"))->toBe(3);
    })->with([
        'write' => ['DELETE FROM orders'],
        'two statements' => ['SELECT 1; DELETE FROM orders'],
        'USE' => ['USE mysql'],
    ]);

    test('a query runs in a read-only session and transaction, and the session is reset afterwards', function () {
        ($this->csv)(['query' => 'SELECT @@transaction_read_only AS ro, @@session.transaction_read_only AS session_ro']);

        expect(file_get_contents($this->directory.'/out.csv'))->toBe("\"ro\",\"session_ro\"\r\n1,1\r\n");
        expect(app(MySQLConnection::class)->scalar('SELECT @@session.transaction_read_only'))->toBe(0);
    });

    test('a query cannot smuggle writes past the single-statement check, or lock rows (security review 7b)', function (string $query) {
        expect(fn () => ($this->csv)(['query' => $query]))->toThrow(DatabaseOperationException::class);

        expect(file_exists($this->directory.'/out.csv'))->toBeFalse();
        expect($this->connection->scalar("SELECT COUNT(*) FROM `{$this->source}`.orders"))->toBe(3);
    })->with([
        'DELIMITER block' => ["DELIMITER \$\$\nSELECT 1; COMMIT; DELETE FROM orders\$\$"],
        'conditional comment' => ['SELECT 1 /*!; COMMIT; DELETE FROM orders # */'],
        'FOR SHARE' => ['SELECT * FROM orders FOR SHARE'],
        'LOCK IN SHARE MODE' => ['SELECT * FROM orders LOCK IN SHARE MODE'],
        'INTO OUTFILE' => ["SELECT * FROM orders INTO OUTFILE '/tmp/x.csv'"],
    ]);

    test('even if the scanner were fooled, the connection runs only one statement per call', function () {
        $pdo = app(MySQLConnection::class)->useCredentials($this->credentials)->pdo();

        expect(fn () => $pdo->query("SELECT 1; DELETE FROM `{$this->source}`.orders"))->toThrow(PDOException::class);
        expect($this->connection->scalar("SELECT COUNT(*) FROM `{$this->source}`.orders"))->toBe(3);
    });

    test('a missing table is reported and leaves no file', function () {
        expect(fn () => ($this->csv)(['table' => 'nope']))->toThrow(DatabaseOperationException::class, 'nope');

        expect(file_exists($this->directory.'/out.csv'))->toBeFalse();
    });

    test('the connection id is handed over before the query runs, and a cancel removes the partial file', function () {
        $connectionId = null;
        $cancelled = false;

        try {
            ($this->csv)(
                ['table' => 'customers'],
                function () use (&$connectionId) {
                    if ($connectionId !== null) {
                        throw new ExportCancelledException;
                    }
                },
                function (int $id) use (&$connectionId) {
                    $connectionId = $id;
                },
            );
        } catch (ExportCancelledException) {
            $cancelled = true;
        }

        expect($cancelled)->toBeTrue()
            ->and($connectionId)->toBeInt()->toBeGreaterThan(0)
            ->and(file_exists($this->directory.'/out.csv'))->toBeFalse();
    });

    test('a query MySQL stops mid-run (a KILL QUERY from Cancel, a timeout) fails and leaves no file', function () {
        $query = 'SELECT /*+ MAX_EXECUTION_TIME(300) */ COUNT(*) FROM information_schema.COLUMNS a, information_schema.COLUMNS b, information_schema.COLUMNS c';

        expect(fn () => ($this->csv)(['query' => $query]))->toThrow(DatabaseOperationException::class);
        expect(file_exists($this->directory.'/out.csv'))->toBeFalse();

        // The connection is usable again afterwards.
        expect(($this->csv)(['table' => 'orders', 'path' => $this->directory.'/after.csv'])->rowsWritten)->toBe(3);
    });
});

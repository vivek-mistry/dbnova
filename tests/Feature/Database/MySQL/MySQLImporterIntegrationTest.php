<?php

use App\Database\Adapters\MySQL\MySQLConnection;
use App\Database\Adapters\MySQL\MySQLImporter;
use App\Database\DTOs\Import\ImportRequest;
use App\Database\DTOs\Import\ImportResult;
use App\Database\Exceptions\DatabaseOperationException;
use App\Database\Exceptions\ReadOnlyModeException;
use App\Database\Import\ImportCancelledException;
use App\Database\Import\SourceStream;

/**
 * Runs real imports through the mysql client when DBNOVA_TEST_DB_USERNAME
 * and an executable DBNOVA_MYSQL_BIN are set.
 */
beforeEach(function () {
    $this->credentials = mySqlTestCredentials();

    if ($this->credentials === null) {
        $this->markTestSkipped('Set DBNOVA_TEST_DB_USERNAME in .env to run MySQL integration tests.');
    }

    if (! is_executable(config()->string('dbnova.binaries.mysql'))) {
        $this->markTestSkipped('Set DBNOVA_MYSQL_BIN to the mysql client to run import tests.');
    }

    $this->connection = app(MySQLConnection::class)->useCredentials($this->credentials);
    $this->database = 'dbnova_test_'.bin2hex(random_bytes(4));
    $this->connection->statement("CREATE DATABASE `{$this->database}`");
    $this->directory = sys_get_temp_dir().'/dbnova-import-'.bin2hex(random_bytes(4));
    mkdir($this->directory);

    $this->import = function (string $sql, array $options = [], ?callable $onProgress = null): ImportResult {
        file_put_contents($path = $this->directory.'/dump.sql', $sql);

        return app(MySQLImporter::class)->import(
            new ImportRequest($this->database, $this->credentials, ...$options),
            SourceStream::open($path, 'sql'),
            $onProgress ?? fn () => null,
        );
    };

    $this->count = fn (string $table): int => (int) $this->connection->scalar("SELECT COUNT(*) FROM `{$this->database}`.`{$table}`");
})->group('mysql');

afterEach(function () {
    if (isset($this->connection, $this->database)) {
        $this->connection->statement("DROP DATABASE IF EXISTS `{$this->database}`");
    }

    if (isset($this->directory)) {
        array_map('unlink', glob($this->directory.'/*') ?: []);
        rmdir($this->directory);
    }
});

test('a dump made for another database is imported into the target, with its database statements skipped', function () {
    $result = ($this->import)(<<<'SQL'
        -- MySQL dump
        /*!40101 SET NAMES utf8mb4 */;
        CREATE DATABASE /*!32312 IF NOT EXISTS*/ `shop`;
        USE `shop`;
        DROP TABLE IF EXISTS `customers`;
        CREATE TABLE `customers` (`id` int NOT NULL, `name` varchar(50), PRIMARY KEY (`id`));
        LOCK TABLES `customers` WRITE;
        INSERT INTO `customers` VALUES (1,'Ada; Lovelace'),(2,'O\'Brien'),(3,'USE other; -- not a statement');
        UNLOCK TABLES;
        SQL, ['sourceDatabase' => 'shop']);

    expect($result->succeeded)->toBeTrue();
    expect($result->skippedStatements)->toBe(2);
    expect($result->statementsSent)->toBe(6);
    expect(($this->count)('customers'))->toBe(3);
    expect($this->connection->scalar("SELECT name FROM `{$this->database}`.customers WHERE id = 2"))->toBe("O'Brien");
});

test('invalid SQL fails with MySQL\'s message and the line in the original file, and earlier statements stay applied', function () {
    $result = ($this->import)((string) file_get_contents(base_path('tests/Fixtures/dumps/invalid.sql')));

    expect($result->succeeded)->toBeFalse();
    expect($result->errorCode)->toBe(1064);
    expect($result->errorLine)->toBe(9);
    expect($result->error)->toContain('You have an error in your SQL syntax');
    expect(($this->count)('items'))->toBe(2);
});

test('error lines stay right after skipped statements, comments and a DELIMITER block', function () {
    $result = ($this->import)(<<<'SQL'
        USE shop;
        -- a comment line
        CREATE TABLE t (n int);

        DELIMITER ;;
        CREATE PROCEDURE p()
        BEGIN
          INSERT INTO t VALUES (1); INSERT INTO t VALUES (2);
        END ;;
        DELIMITER ;
        /* block
           comment */
        INSERT INTO t VALUES (3);
        INSERT INTO missing VALUES (4);
        SQL, ['sourceDatabase' => 'shop', 'trustCompoundPrograms' => true]);

    expect($result->errorCode)->toBe(1146);
    expect($result->errorLine)->toBe(14);
    expect($this->connection->scalar("SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = '{$this->database}' AND ROUTINE_NAME = 'p'"))->toBe(1);
    expect(($this->count)('t'))->toBe(1);
});

describe('statements that would run outside the target are never sent (security review 6b)', function () {
    beforeEach(function () {
        $this->other = $this->database.'_other';
        $this->connection->statement("CREATE DATABASE `{$this->other}`");
    });

    afterEach(function () {
        if (isset($this->connection, $this->other)) {
            $this->connection->statement("DROP DATABASE IF EXISTS `{$this->other}`");
        }
    });

    test('a second statement hidden behind ";" under another delimiter', function () {
        $result = ($this->import)("DELIMITER ;;\nSET @a = 1; CREATE TABLE `{$this->other}`.pwn1 (i int);;\nDELIMITER ;\n");

        expect($result->succeeded)->toBeFalse();
        expect($result->error)->toContain('contains ";" while the delimiter is ";;"');
        expect($this->connection->scalar("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '{$this->other}'"))->toBe(0);
    });

    test('a quoted DELIMITER that the client reads differently', function () {
        $result = ($this->import)("DELIMITER 'x'\nSET @v = 1 x CREATE TABLE `{$this->other}`.pwn2 (i int) COMMENT 'x'\n");

        expect($result->succeeded)->toBeFalse();
        expect($result->error)->toContain("DELIMITER 'x' is not supported");
        expect($this->connection->scalar("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '{$this->other}'"))->toBe(0);
    });

    test('a routine with a multi-statement body needs the user\'s trust', function () {
        $sql = "DELIMITER ;;\nCREATE PROCEDURE p() BEGIN SET @a = 1; SET @b = 2; END;;\nDELIMITER ;\n";

        expect(($this->import)($sql)->error)->toContain('Confirm that you trust this dump');
        expect($this->connection->scalar("SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = '{$this->database}'"))->toBe(0);

        expect(($this->import)($sql, ['trustCompoundPrograms' => true])->succeeded)->toBeTrue();
    });
});

test('whitespace before a multi-character delimiter is kept, so the client splits where the scanner did', function () {
    // Without the space, "LIKE a$" + "$$" would reach the client as "LIKE a$$$" and split after "a".
    $result = ($this->import)("CREATE TABLE a\$ (i int);\nDELIMITER $$\nCREATE TABLE b\$ LIKE a\$ $$\nDELIMITER ;\n");

    expect($result->error)->toBeNull();
    expect($result->succeeded)->toBeTrue();
    expect($this->connection->scalar("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '{$this->database}' AND TABLE_NAME = 'b\$'"))->toBe(1);
});

test('a statement ending in a line comment is still terminated', function () {
    $result = ($this->import)("CREATE TABLE t (n int);\nINSERT INTO t VALUES (1) -- trailing comment\n;\nINSERT INTO t VALUES (2);\n");

    expect($result->succeeded)->toBeTrue();
    expect(($this->count)('t'))->toBe(2);
});

test('speed mode turns off foreign key checks for the session and commits at the end', function () {
    $sql = <<<'SQL'
        CREATE TABLE parent (id int PRIMARY KEY) ENGINE=InnoDB;
        CREATE TABLE child (id int PRIMARY KEY, parent_id int, FOREIGN KEY (parent_id) REFERENCES parent (id)) ENGINE=InnoDB;
        INSERT INTO child VALUES (1, 1);
        INSERT INTO parent VALUES (1);
        SQL;

    expect(($this->import)($sql)->errorCode)->toBe(1452);

    $this->connection->statement("DROP DATABASE `{$this->database}`");
    $this->connection->statement("CREATE DATABASE `{$this->database}`");

    $result = ($this->import)($sql, ['speedMode' => true]);

    expect($result->succeeded)->toBeTrue();
    expect(($this->count)('child'))->toBe(1);
    expect(($this->count)('parent'))->toBe(1);
});

test('a cancel stops the mysql client while it runs a long statement', function () {
    $startedAt = microtime(true);

    expect(fn () => ($this->import)(
        "CREATE TABLE t (n int);\nINSERT INTO t SELECT SLEEP(20);\n",
        [],
        function () use ($startedAt): void {
            if (microtime(true) - $startedAt > 0.5) {
                throw new ImportCancelledException;
            }
        },
    ))->toThrow(ImportCancelledException::class);

    expect(microtime(true) - $startedAt)->toBeLessThan(8.0);
});

test('progress keeps being reported while MySQL works on one long statement', function () {
    $ticks = 0;

    ($this->import)("CREATE TABLE t (n int);\nINSERT INTO t SELECT SLEEP(1);\n", [], function () use (&$ticks): void {
        $ticks++;
    });

    expect($ticks)->toBeGreaterThan(100);
});

test('read-only mode refuses imports', function () {
    config(['dbnova.read_only' => true]);

    expect(fn () => ($this->import)('CREATE TABLE t (n int);'))->toThrow(ReadOnlyModeException::class);
});

test('a target database that is gone is reported before anything runs', function () {
    $this->connection->statement("DROP DATABASE `{$this->database}`");

    expect(fn () => ($this->import)('CREATE TABLE t (n int);'))->toThrow(DatabaseOperationException::class, 'does not exist');
});

test('memory stays flat while a large dump is imported', function () {
    $path = $this->directory.'/dump.sql';
    $file = fopen($path, 'wb');
    fwrite($file, "CREATE TABLE t (id int PRIMARY KEY, note varchar(100));\n");

    for ($batch = 0; $batch < 400; $batch++) {
        $rows = [];

        for ($row = 0; $row < 1000; $row++) {
            $id = $batch * 1000 + $row;
            $rows[] = "({$id},'row {$id} with some padding text to make it longer than a few bytes')";
        }

        fwrite($file, 'INSERT INTO t VALUES '.implode(',', $rows).";\n");
    }

    fclose($file);
    gc_collect_cycles();
    $before = memory_get_usage();
    $peak = 0;

    $result = app(MySQLImporter::class)->import(
        new ImportRequest($this->database, $this->credentials),
        SourceStream::open($path, 'sql'),
        function () use ($before, &$peak): void {
            $peak = max($peak, memory_get_usage() - $before);
        },
    );

    expect($result->succeeded)->toBeTrue();
    expect(($this->count)('t'))->toBe(400_000);
    expect(filesize($path))->toBeGreaterThan(30 * 1024 * 1024);
    expect($peak)->toBeLessThan(16 * 1024 * 1024);
});

<?php

use App\Database\Adapters\MySQL\MySQLImporter;
use App\Database\DTOs\Import\PreflightReport;
use App\Database\Import\ImportCancelledException;
use App\Database\Import\SourceStream;
use App\Database\Sql\SqlStreamScanner;

beforeEach(function () {
    $this->directory = sys_get_temp_dir().'/dbnova-preflight-'.bin2hex(random_bytes(4));
    mkdir($this->directory);

    $this->preflight = function (string $sql, string $format = 'sql', ?callable $onProgress = null): PreflightReport {
        $path = $this->directory.'/dump.'.$format;
        file_put_contents($path, $format === 'gz' ? gzencode($sql) : $sql);

        return app(MySQLImporter::class)->preflight(SourceStream::open($path, $format), $onProgress);
    };
});

afterEach(function () {
    array_map('unlink', glob($this->directory.'/*') ?: []);
    rmdir($this->directory);
});

function dumpFixture(string $name): string
{
    return (string) file_get_contents(base_path('tests/Fixtures/dumps/'.$name));
}

test('a dump made for one database is accepted and its database statements will be skipped', function () {
    $report = ($this->preflight)(dumpFixture('single-db.sql'));

    expect($report->accepted())->toBeTrue();
    expect($report->databases)->toBe(['shop']);
    expect($report->sourceDatabase)->toBe('shop');
    expect($report->skippedStatements)->toBe(2);
    expect($report->tables)->toBe(['customers', 'orders']);
    expect($report->tableCount)->toBe(2);
    expect($report->storedObjectCount)->toBe(1);
    expect($report->disablesForeignKeyChecks)->toBeTrue();
    expect($report->compoundProgramCount)->toBe(1);
    expect($report->warnings)->toHaveCount(2);
    expect($report->warnings[0])->toContain('DEFINER');
    expect($report->warnings[1])->toContain('multi-statement bodies');
    expect($report->sqlBytes)->toBe(strlen(dumpFixture('single-db.sql')));
});

test('a dump without database statements is accepted as is', function () {
    $report = ($this->preflight)(dumpFixture('plain.sql'));

    expect($report->accepted())->toBeTrue();
    expect($report->databases)->toBe([]);
    expect($report->sourceDatabase)->toBeNull();
    expect($report->skippedStatements)->toBe(0);
    expect($report->tables)->toBe(['products']);
    expect($report->statementCount)->toBe(4);
});

test('a dump of two databases is rejected with their names', function () {
    $report = ($this->preflight)(dumpFixture('multi-db.sql'));

    expect($report->accepted())->toBeFalse();
    expect($report->databases)->toBe(['crm', 'shop']);
    expect($report->problems[0])->toContain('2 databases (`crm`, `shop`)');
});

test('database names are found in every kind of database statement', function (string $sql, array $databases, bool $accepted) {
    $report = ($this->preflight)($sql);

    expect($report->databases)->toBe($databases);
    expect($report->accepted())->toBe($accepted);
})->with([
    'drop of the same database' => ["DROP DATABASE IF EXISTS `shop`;\nCREATE DATABASE `shop`;\nUSE shop;\nCREATE TABLE t (id int);", ['shop'], true],
    'drop of another database' => ["USE shop;\nDROP DATABASE crm;", ['crm', 'shop'], false],
    'schema synonym' => ["CREATE SCHEMA shop;\nUSE crm;", ['crm', 'shop'], false],
    'alter database by name' => ["USE shop;\nALTER DATABASE `crm` CHARACTER SET utf8mb4;", ['crm', 'shop'], false],
    'alter of the default database' => ["USE shop;\nALTER DATABASE CHARACTER SET utf8mb4;", ['shop'], true],
    'database names inside strings and comments' => ["-- USE crm;\nINSERT INTO t VALUES ('USE crm; DROP DATABASE x');\n/* CREATE DATABASE y; */ SET @a = 1;", [], true],
    'qualified name of the same database' => ["USE shop;\nINSERT INTO `shop`.`orders` VALUES (1);", ['shop'], false],
]);

test('a database-qualified table name cannot be redirected and says where it is', function () {
    $report = ($this->preflight)("SET NAMES utf8mb4;\nINSERT INTO `crm`.`contacts` VALUES (1);");

    expect($report->problems)->toHaveCount(1);
    expect($report->problems[0])->toStartWith('Line 2: It names the database `crm`');
});

test('statements that could act outside the target database or that the client reads differently are refused', function (string $sql, string $problem) {
    $report = ($this->preflight)($sql);

    expect($report->accepted())->toBeFalse();
    expect(implode("\n", $report->problems))->toContain($problem);
})->with([
    'rename to another database' => ['RENAME TABLE a TO other.a;', 'RENAME statements are not part of a database dump'],
    'second name in a drop list' => ['DROP TABLE IF EXISTS a, other.b;', 'names the database `other`'],
    'update elsewhere' => ['UPDATE other.t SET a = 1;', 'UPDATE statements'],
    'delete' => ['DELETE FROM t;', 'DELETE statements'],
    'select' => ['SELECT 1;', 'SELECT statements'],
    'call' => ['CALL p();', 'CALL statements'],
    'load data local' => ["LOAD DATA LOCAL INFILE '/etc/passwd' INTO TABLE t;", 'LOAD statements'],
    'index on another database' => ['CREATE INDEX i ON other.t (a);', 'names the database `other`'],
    'trigger on another database' => ['CREATE TRIGGER x BEFORE INSERT ON other.t FOR EACH ROW SET @a = 1;', 'names the database `other`'],
    'view in another database' => ['CREATE OR REPLACE VIEW other.v AS SELECT 1;', 'names the database `other`'],
    'alter table rename' => ['ALTER TABLE t RENAME TO other.t;', 'RENAME / EXCHANGE'],
    'lock list' => ['LOCK TABLES t WRITE, other.u READ;', 'names the database `other`'],
    'unrecognised create' => ['CREATE USER x;', 'This CREATE statement is not part of a database dump'],
    'line starting with --x' => ["--x '\nUSE other;\n-- '\n;", 'X statements'],
    'delimiter inside a conditional comment' => ['/*!00000 SET @a = 1; USE other */;', 'contains the delimiter ";"'],
    'client command' => ["SET @a = 1\n\\C gbk\n;", 'mysql client command'],
    'delimiter command mid-statement' => ["INSERT INTO t VALUES (1)\ndelimiter $$\n;", 'DELIMITER command appears'],
    'multibyte charset' => ['SET NAMES gbk;', 'multibyte character set'],
    'no backslash escapes' => ["SET SESSION sql_mode = 'NO_BACKSLASH_ESCAPES';", 'NO_BACKSLASH_ESCAPES'],
    'second statement behind ";" under another delimiter' => ["DELIMITER ;;\nSET @a = 1; CREATE TABLE other.pwn1 (i int);;\nDELIMITER ;", 'contains ";" while the delimiter is ";;"'],
    'second statement in a view under another delimiter' => ["DELIMITER $$\nCREATE VIEW v AS SELECT 1; DROP TABLE t$$\nDELIMITER ;", 'contains ";" while the delimiter is "$$"'],
    'quoted delimiter' => ["DELIMITER 'x'\nSET @v = 1 x", "DELIMITER 'x' is not supported"],
    'delimiter with a backslash' => ["DELIMITER a\\b\nSET @v = 1 a\\b", 'is not supported'],
]);

test('what mysqldump, phpMyAdmin and Adminer write is accepted', function () {
    $sql = <<<'SQL'
        START TRANSACTION;
        SET time_zone = "+00:00";
        /*!40101 SET NAMES utf8mb4 */;
        SET foreign_key_checks = 0;
        DROP TABLE IF EXISTS `t`;
        CREATE TABLE `t` (`id` int NOT NULL, `price` decimal(10,2) DEFAULT '1.50');
        LOCK TABLES `t` WRITE;
        /*!40000 ALTER TABLE `t` DISABLE KEYS */;
        INSERT INTO `t` VALUES (1,10.50),(2,'it\'s; fine');
        /*!40000 ALTER TABLE `t` ENABLE KEYS */;
        UNLOCK TABLES;
        ALTER TABLE `t` ADD PRIMARY KEY (`id`);
        ALTER TABLE `t` MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;
        CREATE INDEX `t_price` ON `t` (`price`);
        TRUNCATE TABLE `t`;
        /*!50001 DROP VIEW IF EXISTS `v`*/;
        /*!50001 CREATE ALGORITHM=UNDEFINED */ /*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */ /*!50001 VIEW `v` AS select `t`.`id` AS `id` from `t` */;
        /*!50003 DROP PROCEDURE IF EXISTS `p` */;
        DELIMITER ;;
        CREATE DEFINER=`root`@`localhost` PROCEDURE `p`()
        BEGIN
          SELECT COUNT(*) FROM t; UPDATE t SET price = 0;
        END ;;
        DELIMITER ;
        COMMIT;
        SQL;

    $report = ($this->preflight)($sql);

    expect($report->problems)->toBe([]);
    expect($report->storedObjectCount)->toBe(2);
    expect($report->compoundProgramCount)->toBe(1);
    expect(implode("\n", $report->warnings))->toContain('1 routine(s), trigger(s) or event(s) with multi-statement bodies');
});

test('a gzip dump gives the same report as the plain file', function () {
    $plain = ($this->preflight)(dumpFixture('single-db.sql'));
    $gzip = ($this->preflight)(dumpFixture('single-db.sql'), 'gz');

    expect($gzip->toArray())->toBe($plain->toArray());
});

test('broken files are rejected with a reason', function (string $sql, string $problem) {
    $report = ($this->preflight)($sql);

    expect($report->accepted())->toBeFalse();
    expect($report->problems[0])->toContain($problem);
})->with([
    'truncated inside a string' => ["CREATE TABLE t (a text);\nINSERT INTO t VALUES ('cut off here", 'looks truncated'],
    'comments only' => ["-- nothing here\n/* at all */\n", 'No SQL statements'],
    'empty' => ['', 'No SQL statements'],
]);

test('a statement over the size limit is rejected with its line', function () {
    config(['dbnova.limits.max_statement_bytes' => 1024 * 1024, 'dbnova.limits.pump_chunk_bytes' => 64 * 1024]);

    $report = ($this->preflight)("SELECT 1;\nINSERT INTO t VALUES ('".str_repeat('x', 2 * 1024 * 1024)."');");

    expect($report->accepted())->toBeFalse();
    expect($report->problems[0])->toContain('line 2 is larger than 1 MB');
});

test('statements that need administrator privileges are warned about', function () {
    $report = ($this->preflight)("SET @@GLOBAL.GTID_PURGED=/*!80000 '+'*/ 'abc:1-5';");

    expect($report->accepted())->toBeTrue();
    expect($report->warnings)->toHaveCount(1);
    expect($report->warnings[0])->toContain('GTID_PURGED');
});

test('progress is reported while scanning and the scan can be stopped', function () {
    config(['dbnova.limits.pump_chunk_bytes' => 1024]);
    $sql = str_repeat("INSERT INTO t VALUES (1,'abc');\n", 500);
    $calls = [];

    ($this->preflight)($sql, 'sql', function (int $position, int $statements) use (&$calls): void {
        $calls[] = [$position, $statements];
    });

    expect(count($calls))->toBeGreaterThan(10);
    expect(end($calls)[0])->toBe(strlen($sql));

    expect(fn () => ($this->preflight)($sql, 'sql', fn () => throw new ImportCancelledException))
        ->toThrow(ImportCancelledException::class);
});

test('only the source database statements are skipped during import', function () {
    $statements = SqlStreamScanner::split("CREATE DATABASE `shop`;\nUSE shop;\nCREATE TABLE t (id int);\nUSE `crm`;");

    expect(array_map(fn ($statement) => MySQLImporter::skipsStatement($statement, 'shop'), $statements))->toBe([true, true, false, false]);
    expect(array_map(fn ($statement) => MySQLImporter::skipsStatement($statement, null), $statements))->toBe([false, false, false, false]);
});

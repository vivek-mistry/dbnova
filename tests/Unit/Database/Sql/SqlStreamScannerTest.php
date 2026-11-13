<?php

use App\Database\Exceptions\SqlStatementTooLargeException;
use App\Database\Sql\SqlStatement;
use App\Database\Sql\SqlStreamScanner;

/**
 * @return list<string>
 */
function statementsOf(string $sql): array
{
    return array_map(fn (SqlStatement $statement): string => $statement->sql, SqlStreamScanner::split($sql));
}

/**
 * Feed the text in chunks of the given size.
 *
 * @return list<string>
 */
function statementsInChunks(string $sql, int $chunkSize): array
{
    $scanner = new SqlStreamScanner;
    $statements = [];

    foreach (str_split($sql, $chunkSize) as $chunk) {
        array_push($statements, ...$scanner->feed($chunk));
    }

    array_push($statements, ...$scanner->finish());

    return array_map(fn (SqlStatement $statement): string => $statement->sql, $statements);
}

test('statements are split on the delimiter only outside strings, identifiers and comments', function (string $sql, array $expected) {
    expect(statementsOf($sql))->toBe($expected);
})->with([
    'simple' => ['SELECT 1; SELECT 2', ['SELECT 1', 'SELECT 2']],
    'trailing delimiter and whitespace' => ["SELECT 1;\n\n  ", ['SELECT 1']],
    'semicolon in single quotes' => ["SELECT 'a;b'; SELECT 2", ["SELECT 'a;b'", 'SELECT 2']],
    'semicolon in double quotes' => ['SELECT "a;b"; SELECT 2', ['SELECT "a;b"', 'SELECT 2']],
    'semicolon in backticks' => ['SELECT `a;b` FROM t; SELECT 2', ['SELECT `a;b` FROM t', 'SELECT 2']],
    'backslash-escaped quote' => ["SELECT 'it\\'s;'; SELECT 2", ["SELECT 'it\\'s;'", 'SELECT 2']],
    'doubled quote' => ["SELECT 'it''s;'; SELECT 2", ["SELECT 'it''s;'", 'SELECT 2']],
    'doubled backtick' => ['SELECT `a``;b`; SELECT 2', ['SELECT `a``;b`', 'SELECT 2']],
    'double-dash comment' => ["SELECT 1 -- ; not a delimiter\n; SELECT 2", ['SELECT 1 -- ; not a delimiter', 'SELECT 2']],
    // The mysql client (which runs imports) only treats "--" + whitespace as a comment.
    'double-dash before a control character is not a comment' => ["SELECT 1 --\x01;USE other;", ["SELECT 1 --\x01", 'USE other']],
    'double-dash without a space is an operator' => ['SELECT 5--2; SELECT 3', ['SELECT 5--2', 'SELECT 3']],
    'hash comment' => ["SELECT 1 # ; not a delimiter\n; SELECT 2", ['SELECT 1 # ; not a delimiter', 'SELECT 2']],
    'block comment' => ['SELECT /* ; */ 1; SELECT 2', ['SELECT /* ; */ 1', 'SELECT 2']],
    'comment-only statements are skipped' => ["-- just a note\n; /* another */ ; SELECT 1", ['SELECT 1']],
    'empty statements are skipped' => [';;; SELECT 1 ;;', ['SELECT 1']],
    'conditional comment is a statement' => ['/*!40101 SET NAMES utf8mb4 */; SELECT 1', ['/*!40101 SET NAMES utf8mb4 */', 'SELECT 1']],
]);

test('DELIMITER changes the delimiter, so procedure bodies stay whole', function () {
    $sql = <<<'SQL'
        DELIMITER $$
        CREATE PROCEDURE p()
        BEGIN
          SELECT 1;
          SELECT 2;
        END$$
        DELIMITER ;
        CALL p();
        SQL;

    expect(statementsOf($sql))->toBe([
        "CREATE PROCEDURE p()\nBEGIN\n  SELECT 1;\n  SELECT 2;\nEND",
        'CALL p()',
    ]);
});

test('DELIMITER after a comment line is still recognised', function () {
    expect(statementsOf("-- set up\nDELIMITER //\nSELECT 1//\nDELIMITER ;\nSELECT 2;"))->toBe(['SELECT 1', 'SELECT 2']);
});

test('statements know the line they start on', function () {
    $statements = SqlStreamScanner::split("SELECT 1;\n\n-- note\nSELECT\n2;\n  SELECT 'a\nb';\nSELECT 4");

    expect(array_map(fn (SqlStatement $statement): int => $statement->line, $statements))->toBe([1, 4, 6, 8]);
});

test('any chunking produces the same statements as the whole text', function (int $chunkSize) {
    $sql = <<<'SQL'
        SET NAMES utf8mb4;
        /*!40101 SET @OLD = @@SQL_MODE */;
        -- comment with 'quote' and ; delimiter
        INSERT INTO `t;1` VALUES ('a;b', "c\"d", 'e''f', 'g\\'), (5--2, NULL); # trailing
        DELIMITER $$
        CREATE TRIGGER trg BEFORE INSERT ON t FOR EACH ROW BEGIN SET NEW.a = 'x;y'; END$$
        DELIMITER ;
        UPDATE t SET a = 'WHERE' /* no where here */;
        SQL;

    expect(statementsInChunks($sql, $chunkSize))->toBe(statementsOf($sql));
})->with([1, 2, 3, 5, 7, 11, 64]);

test('statements carry the delimiter that ended them', function () {
    $statements = SqlStreamScanner::split("SELECT 1;\nDELIMITER ;;\nCREATE TRIGGER t BEGIN SET @a = 1; END;;\nDELIMITER ;\nSELECT 2;");

    expect(array_map(fn (SqlStatement $statement): string => $statement->delimiter, $statements))->toBe([';', ';;', ';']);
});

test('a text that ends inside a string or block comment is reported as truncated', function (string $sql, bool $truncated) {
    $scanner = new SqlStreamScanner;
    $scanner->feed($sql);
    $scanner->finish();

    expect($scanner->isInsideLiteral())->toBe($truncated);
})->with([
    'complete' => ["INSERT INTO t VALUES ('a');", false],
    'open string' => ["INSERT INTO t VALUES ('a", true],
    'open identifier' => ['SELECT `a', true],
    'open block comment' => ['SELECT 1 /* never closed', true],
    'open line comment is fine' => ['SELECT 1 -- trailing', false],
]);

test('statements know where their text starts and whether they hold a client command', function () {
    $statements = SqlStreamScanner::split("SET @a = 1;\n-- note\n# more\nINSERT INTO t\nVALUES ('a\\\\b');\nSET @b = 2\n\\C gbk\n;");

    expect(array_map(fn (SqlStatement $statement): array => [$statement->firstLine, $statement->line, $statement->hasClientCommand], $statements))
        ->toBe([[1, 1, false], [2, 4, false], [6, 6, true]]);
});

test('unconsumed input counts toward the size limit, so a DELIMITER line without a newline cannot grow forever', function () {
    $scanner = new SqlStreamScanner(maxStatementBytes: 1024);

    expect(fn () => $scanner->feed('DELIMITER '.str_repeat('x', 2048)))->toThrow(SqlStatementTooLargeException::class);
});

test('a statement larger than the limit stops the scan with its line', function () {
    $scanner = new SqlStreamScanner(maxStatementBytes: 1024);
    $scanner->feed("SELECT 1;\n\nINSERT INTO t VALUES ('".str_repeat('x', 600));

    expect(fn () => $scanner->feed(str_repeat('y', 600)))
        ->toThrow(SqlStatementTooLargeException::class, 'line 3');
});

test('leading tokens skip comments, unquote identifiers and read conditional comments', function () {
    $statement = SqlStreamScanner::split("-- note\nCREATE DATABASE /*!32312 IF NOT EXISTS*/ `my``shop` /*!40100 DEFAULT CHARACTER SET utf8mb4 */")[0];

    expect($statement->leadingTokens(6))->toBe([
        ['type' => 'word', 'value' => 'CREATE'],
        ['type' => 'word', 'value' => 'DATABASE'],
        ['type' => 'word', 'value' => 'IF'],
        ['type' => 'word', 'value' => 'NOT'],
        ['type' => 'word', 'value' => 'EXISTS'],
        ['type' => 'identifier', 'value' => 'my`shop'],
    ]);
});

test('a statement is available as soon as its delimiter arrives', function () {
    $scanner = new SqlStreamScanner;

    expect($scanner->feed('SELECT 1; SEL'))->toHaveCount(1);
    expect($scanner->feed('ECT 2'))->toBe([]);
    expect(array_map(fn (SqlStatement $statement): string => $statement->sql, $scanner->finish()))->toBe(['SELECT 2']);
});

describe('statement classification', function () {
    test('keywords skip comments and read conditional comments', function (string $sql, string $keyword) {
        expect(SqlStreamScanner::split($sql)[0]->keyword())->toBe($keyword);
    })->with([
        'plain' => ['select 1', 'SELECT'],
        'after a comment' => ["-- hi\n  update t set a = 1 where id = 1", 'UPDATE'],
        'conditional comment' => ['/*!40101 SET NAMES utf8 */', 'SET'],
        'parenthesised select' => ['(SELECT 1)', 'SELECT'],
    ]);

    test('destructive statements are recognised', function (string $sql, bool $destructive) {
        expect(SqlStreamScanner::split($sql)[0]->isDestructive())->toBe($destructive);
    })->with([
        'drop table' => ['DROP TABLE t', true],
        'drop database' => ['drop database shop', true],
        'truncate' => ['TRUNCATE t', true],
        'delete without where' => ['DELETE FROM t', true],
        'update without where' => ['UPDATE t SET a = 1', true],
        'WHERE only inside a string' => ["UPDATE t SET a = 'WHERE'", true],
        'WHERE only inside a comment' => ['DELETE FROM t /* WHERE id = 1 */', true],
        'WHERE only inside backticks' => ['UPDATE t SET `where` = 1', true],
        'delete with where' => ['DELETE FROM t WHERE id = 1', false],
        'update with where' => ["UPDATE t SET a = 1\nwhere id = 2", false],
        'select' => ['SELECT * FROM t', false],
        'insert' => ['INSERT INTO t VALUES (1)', false],
    ]);

    test('read-only statements are recognised', function (string $sql, bool $readOnly) {
        expect(SqlStreamScanner::split($sql)[0]->isReadOnly())->toBe($readOnly);
    })->with([
        'select' => ['SELECT 1', true],
        'show' => ['SHOW TABLES', true],
        'describe' => ['DESCRIBE t', true],
        'explain' => ['EXPLAIN SELECT 1', true],
        'with' => ['WITH x AS (SELECT 1) SELECT * FROM x', true],
        'insert' => ['INSERT INTO t VALUES (1)', false],
        'set' => ['SET @a = 1', false],
        'call' => ['CALL p()', false],
    ]);
});

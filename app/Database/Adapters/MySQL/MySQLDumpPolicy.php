<?php

namespace App\Database\Adapters\MySQL;

use App\Database\Sql\SqlStatement;

/**
 * Which statements an import accepts. Imports are for database dumps, so
 * only the statement kinds that dump tools write (mysqldump, phpMyAdmin,
 * Adminer, ...) are allowed, and table names in them must not name another
 * database. Statements where this scanner and the mysql client could split
 * the text differently are refused too, so what preflight inspected is
 * exactly what MySQL runs.
 *
 * This is a best-effort guard for the "one database" rule. The real
 * boundary is the MySQL account's grants; routine and trigger bodies are
 * not inspected.
 */
final class MySQLDumpPolicy
{
    /** Words that may come between CREATE/DROP/ALTER and the object keyword. */
    private const array OBJECT_KEYWORDS = ['TABLE', 'VIEW', 'TRIGGER', 'PROCEDURE', 'FUNCTION', 'EVENT', 'INDEX', 'DATABASE', 'SCHEMA'];

    /** Multibyte character sets whose second byte can be a backslash, so the client would read strings differently. */
    private const string UNSAFE_CHARSETS = '/\b(NAMES|CHARACTER\s+SET|CHARSET|character_set_\w+)\b[^;]*?\b(big5|cp932|gb18030|gbk|sjis)\b/i';

    /** SET statements longer than this are only checked in their first part. */
    private const int SET_CHECK_BYTES = 65536;

    /**
     * Why the statement cannot be imported, or null when it can.
     */
    public static function violation(SqlStatement $statement): ?string
    {
        $keyword = $statement->keyword();

        $problem = match ($keyword) {
            'SET' => self::checkSet($statement),
            'INSERT', 'REPLACE' => self::checkName($statement->leadingTokens(16), 1, ['INTO', 'IGNORE', 'LOW_PRIORITY', 'DELAYED', 'HIGH_PRIORITY']),
            'TRUNCATE' => self::checkName($statement->leadingTokens(8), 1, ['TABLE']),
            'CREATE', 'DROP', 'ALTER' => self::checkDefinition($statement, $keyword),
            'LOCK' => self::checkNoQualifiedName($statement->leadingTokens(512)),
            'UNLOCK', 'COMMIT', 'START', 'USE' => null,
            default => sprintf(
                '%s statements are not part of a database dump, so imports do not run them. Run them in the SQL editor instead.',
                $keyword === '' ? 'Unrecognised' : $keyword,
            ),
        };

        return $problem ?? self::clientDisagreement($statement);
    }

    private static function checkSet(SqlStatement $statement): ?string
    {
        $text = substr($statement->sql, 0, self::SET_CHECK_BYTES);

        if (stripos($text, 'NO_BACKSLASH_ESCAPES') !== false) {
            return 'The dump turns on NO_BACKSLASH_ESCAPES, which changes how strings are read. Re-export it without that SQL mode.';
        }

        if (preg_match(self::UNSAFE_CHARSETS, $text) === 1) {
            return 'The dump switches to a multibyte character set (big5, cp932, gb18030, gbk or sjis) that changes how strings are read. Re-export it as utf8mb4.';
        }

        return null;
    }

    /**
     * CREATE / DROP / ALTER of a table, view, routine, trigger, event, index or database.
     */
    private static function checkDefinition(SqlStatement $statement, string $keyword): ?string
    {
        $tokens = $statement->leadingTokens(64);
        $objectIndex = null;

        foreach ($tokens as $index => $token) {
            if ($index > 0 && $token['type'] === 'word' && in_array(strtoupper($token['value']), self::OBJECT_KEYWORDS, true)) {
                $objectIndex = $index;

                break;
            }
        }

        if ($objectIndex === null) {
            return sprintf('This %s statement is not part of a database dump, so imports do not run it.', $keyword);
        }

        $object = strtoupper($tokens[$objectIndex]['value']);

        if ($object === 'DATABASE' || $object === 'SCHEMA') {
            return null; // Database statements are counted and skipped by preflight.
        }

        if ($keyword === 'ALTER' && $object === 'TABLE' && preg_match('/\b(RENAME|EXCHANGE)\b/i', $statement->code()) === 1) {
            return 'ALTER TABLE … RENAME / EXCHANGE PARTITION statements are not imported, because they can move tables to another database.';
        }

        if ($keyword === 'DROP' && ($object === 'TABLE' || $object === 'VIEW')) {
            // DROP TABLE a, b, c: every name in the list must be unqualified.
            return self::checkNoQualifiedName(array_slice($statement->leadingTokens(512), $objectIndex));
        }

        $problem = self::checkName($tokens, $objectIndex + 1, ['IF', 'NOT', 'EXISTS']);

        if ($problem === null && ($object === 'INDEX' || $object === 'TRIGGER')) {
            // CREATE INDEX i ON t / CREATE TRIGGER x BEFORE INSERT ON t / DROP INDEX i ON t.
            foreach ($tokens as $index => $token) {
                if ($index > $objectIndex && $token['type'] === 'word' && strtoupper($token['value']) === 'ON') {
                    return self::checkName($tokens, $index + 1, []);
                }
            }
        }

        return $problem;
    }

    /**
     * The name at $index (after skipping $skip words) must not be database-qualified.
     *
     * @param  list<array{type: 'word'|'identifier'|'string'|'symbol', value: string}>  $tokens
     * @param  list<string>  $skip
     */
    private static function checkName(array $tokens, int $index, array $skip): ?string
    {
        while (isset($tokens[$index]) && $tokens[$index]['type'] === 'word' && in_array(strtoupper($tokens[$index]['value']), $skip, true)) {
            $index++;
        }

        $name = $tokens[$index] ?? null;
        $next = $tokens[$index + 1] ?? null;

        if ($name !== null && $next !== null && $next['type'] === 'symbol' && $next['value'] === '.') {
            return self::qualifiedNameProblem($name['value']);
        }

        return null;
    }

    /**
     * No name in the token list may be database-qualified (for lists of tables).
     *
     * @param  list<array{type: 'word'|'identifier'|'string'|'symbol', value: string}>  $tokens
     */
    private static function checkNoQualifiedName(array $tokens): ?string
    {
        foreach ($tokens as $index => $token) {
            if ($token['type'] === 'symbol' && $token['value'] === '.' && $index > 0) {
                return self::qualifiedNameProblem($tokens[$index - 1]['value']);
            }
        }

        return null;
    }

    private static function qualifiedNameProblem(string $database): string
    {
        return sprintf(
            'It names the database `%s` inside a statement (`%s`.`table`), so it cannot be redirected to the target. Re-export without database-qualified table names.',
            $database,
            $database,
        );
    }

    /**
     * Text the mysql client would split or interpret differently from the scanner.
     */
    private static function clientDisagreement(SqlStatement $statement): ?string
    {
        if ($statement->hasClientCommand) {
            return 'It contains a mysql client command (a backslash outside a string, such as \\C), which imports do not allow.';
        }

        if (str_contains($statement->sql, '/*!') && $statement->conditionalCommentContains($statement->delimiter)) {
            return sprintf('A conditional comment (/*! … */) contains the delimiter "%s", which the mysql client would split differently.', $statement->delimiter);
        }

        if (stripos($statement->sql, 'delimiter') !== false && preg_match('/\n[ \t]*delimiter[ \t]/i', $statement->code()) === 1) {
            return 'A DELIMITER command appears in the middle of a statement.';
        }

        return null;
    }
}

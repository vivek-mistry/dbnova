<?php

namespace App\Database\Adapters\MySQL;

use App\Database\Sql\SqlStatement;

/**
 * Reads what a dump statement does to databases, from its first tokens
 * only (cheap even for a multi-megabyte INSERT). Statement bodies are
 * never rewritten: a statement is either sent as written or skipped.
 */
final class MySQLDumpStatement
{
    /** Words that may follow ALTER DATABASE when no name is given (the default database). */
    private const array ALTER_DATABASE_OPTIONS = ['CHARACTER', 'CHARSET', 'DEFAULT', 'COLLATE', 'ENCRYPTION', 'READ'];

    /**
     * The database this statement switches to, creates, drops or alters by
     * name, e.g. ['kind' => 'use', 'name' => 'shop'], or null.
     *
     * @return array{kind: 'use'|'create'|'drop'|'alter', name: string}|null
     */
    public static function databaseStatement(SqlStatement $statement): ?array
    {
        $words = self::words($statement, 8);
        $keyword = strtoupper($words[0]['value'] ?? '');

        if ($keyword === 'USE') {
            $name = $words[1] ?? null;

            return $name === null ? null : ['kind' => 'use', 'name' => $name['value']];
        }

        if (! in_array($keyword, ['CREATE', 'DROP', 'ALTER'], true)) {
            return null;
        }

        if (! in_array(strtoupper($words[1]['value'] ?? ''), ['DATABASE', 'SCHEMA'], true)) {
            return null;
        }

        $index = 2;

        while (isset($words[$index]) && $words[$index]['type'] === 'word' && in_array(strtoupper($words[$index]['value']), ['IF', 'NOT', 'EXISTS'], true)) {
            $index++;
        }

        $name = $words[$index] ?? null;

        if ($name === null || ($keyword === 'ALTER' && $name['type'] === 'word' && in_array(strtoupper($name['value']), self::ALTER_DATABASE_OPTIONS, true))) {
            return null;
        }

        return ['kind' => match ($keyword) {
            'CREATE' => 'create',
            'DROP' => 'drop',
            default => 'alter',
        }, 'name' => $name['value']];
    }

    /**
     * The table a CREATE TABLE statement creates, or null.
     */
    public static function createdTable(SqlStatement $statement): ?string
    {
        $words = self::words($statement, 8);

        if (strtoupper($words[0]['value'] ?? '') !== 'CREATE') {
            return null;
        }

        $index = 1;

        if (strtoupper($words[$index]['value'] ?? '') === 'TEMPORARY') {
            return null;
        }

        if (strtoupper($words[$index]['value'] ?? '') !== 'TABLE') {
            return null;
        }

        $index++;

        while (isset($words[$index]) && $words[$index]['type'] === 'word' && in_array(strtoupper($words[$index]['value']), ['IF', 'NOT', 'EXISTS'], true)) {
            $index++;
        }

        return $words[$index]['value'] ?? null;
    }

    /**
     * Whether the statement creates a routine, trigger, view or event, which
     * may carry a DEFINER clause.
     */
    public static function createsStoredObject(SqlStatement $statement): bool
    {
        if ($statement->keyword() !== 'CREATE') {
            return false;
        }

        foreach (self::words($statement, 32) as $word) {
            if ($word['type'] === 'word' && in_array(strtoupper($word['value']), ['PROCEDURE', 'FUNCTION', 'TRIGGER', 'VIEW', 'EVENT'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Word and identifier tokens only (symbols such as "=" and "@" dropped).
     *
     * @return list<array{type: 'word'|'identifier'|'string'|'symbol', value: string}>
     */
    private static function words(SqlStatement $statement, int $limit): array
    {
        return array_values(array_filter(
            $statement->leadingTokens($limit),
            fn (array $token): bool => $token['type'] === 'word' || $token['type'] === 'identifier',
        ));
    }
}

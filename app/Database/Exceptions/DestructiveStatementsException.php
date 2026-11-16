<?php

namespace App\Database\Exceptions;

/**
 * The SQL contains DROP, TRUNCATE or an UPDATE/DELETE without WHERE, and the
 * user has not confirmed it yet. Nothing has run.
 */
class DestructiveStatementsException extends DatabaseOperationException
{
    /**
     * @param  list<array{line: int, keyword: string, sql: string}>  $statements
     */
    public function __construct(public readonly array $statements)
    {
        parent::__construct('This SQL contains '.count($statements).' destructive statement'.(count($statements) === 1 ? '' : 's').'. Confirm to run it.');
    }
}

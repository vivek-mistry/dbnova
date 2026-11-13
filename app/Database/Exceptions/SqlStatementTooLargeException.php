<?php

namespace App\Database\Exceptions;

use RuntimeException;

/**
 * One statement in a SQL file is larger than the configured limit, so it
 * cannot be split without holding too much of the file in memory.
 */
class SqlStatementTooLargeException extends RuntimeException
{
    public function __construct(public readonly int $statementLine, int $limitBytes)
    {
        parent::__construct(sprintf(
            'The statement starting on line %d is larger than %d MB. Re-export the dump with smaller INSERT statements (mysqldump --net-buffer-length).',
            $statementLine,
            intdiv($limitBytes, 1024 * 1024),
        ));
    }
}

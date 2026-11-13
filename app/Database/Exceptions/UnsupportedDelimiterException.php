<?php

namespace App\Database\Exceptions;

use RuntimeException;

/**
 * A DELIMITER directive uses quotes, a backslash or whitespace, which the
 * mysql client would interpret differently from DBNova's scanner.
 */
class UnsupportedDelimiterException extends RuntimeException
{
    public function __construct(public readonly int $statementLine, string $delimiter)
    {
        parent::__construct(sprintf(
            'Line %d: DELIMITER %s is not supported (quotes or backslashes in a delimiter are read differently by the mysql client). Use a plain delimiter such as ;; or $$.',
            $statementLine,
            mb_strimwidth($delimiter, 0, 20, '…'),
        ));
    }
}

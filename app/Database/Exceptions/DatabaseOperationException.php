<?php

namespace App\Database\Exceptions;

use PDOException;
use RuntimeException;

/**
 * A managed-database operation failed. The message is safe to show to the
 * user; it never contains credentials.
 */
class DatabaseOperationException extends RuntimeException
{
    /**
     * Friendly messages for common MySQL error codes.
     *
     * @var array<int, string>
     */
    private const array FRIENDLY_MESSAGES = [
        1007 => 'A database with that name already exists.',
        1008 => 'That database does not exist.',
        1044 => 'Your MySQL account does not have permission to do that.',
        1049 => 'That database does not exist.',
        1142 => 'Your MySQL account does not have permission to do that.',
        1227 => 'Your MySQL account does not have permission to do that.',
        2006 => 'The connection to the MySQL server was lost.',
        2013 => 'The connection to the MySQL server was lost.',
    ];

    public static function fromPdoException(PDOException $exception): self
    {
        $errorCode = (int) ($exception->errorInfo[1] ?? $exception->getCode());
        $serverMessage = $exception->errorInfo[2] ?? $exception->getMessage();

        $message = isset(self::FRIENDLY_MESSAGES[$errorCode])
            ? self::FRIENDLY_MESSAGES[$errorCode].' (MySQL: '.$serverMessage.')'
            : 'MySQL error: '.$serverMessage;

        return new self($message, $errorCode, $exception);
    }
}

<?php

namespace App\Database\DTOs\Import;

/**
 * How an import ended. "Sent" means handed to the mysql client: MySQL may
 * have applied any part of it, so a failed import is partial.
 */
final readonly class ImportResult
{
    /**
     * @param  int|null  $errorLine  The line in the original file where the failing statement starts.
     * @param  list<string>  $warnings  The first few MySQL warnings.
     */
    public function __construct(
        public bool $succeeded,
        public int $bytesSent,
        public int $statementsSent,
        public int $skippedStatements,
        public ?string $error = null,
        public ?int $errorCode = null,
        public ?int $errorLine = null,
        public int $warningCount = 0,
        public array $warnings = [],
    ) {}
}

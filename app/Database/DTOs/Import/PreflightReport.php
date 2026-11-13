<?php

namespace App\Database\DTOs\Import;

/**
 * What a full streaming scan of a dump found, and whether it can be
 * imported into one database (ADR 0006).
 */
final readonly class PreflightReport
{
    /**
     * @param  list<string>  $problems  Reasons the import is refused; empty when it is accepted.
     * @param  list<string>  $warnings  Things that may fail or surprise, without refusing the import.
     * @param  list<string>  $databases  Distinct database names the dump refers to.
     * @param  string|null  $sourceDatabase  The one database the dump was made for, when it names exactly one.
     * @param  list<string>  $tables  Tables the dump creates (the first 1,000).
     */
    public function __construct(
        public array $problems,
        public array $warnings,
        public array $databases,
        public ?string $sourceDatabase,
        public int $statementCount,
        public array $tables,
        public int $tableCount,
        public int $skippedStatements,
        public int $storedObjectCount,
        public bool $disablesForeignKeyChecks,
        public int $largestStatementBytes,
        public int $sqlBytes,
        public int $compoundProgramCount = 0,
    ) {}

    /**
     * A report for a file that could not be scanned at all.
     */
    public static function rejected(string $problem): self
    {
        return new self([$problem], [], [], null, 0, [], 0, 0, 0, false, 0, 0);
    }

    public function accepted(): bool
    {
        return $this->problems === [];
    }

    /**
     * @return array{accepted: bool, problems: list<string>, warnings: list<string>, databases: list<string>, sourceDatabase: string|null, statementCount: int, tables: list<string>, tableCount: int, skippedStatements: int, storedObjectCount: int, disablesForeignKeyChecks: bool, largestStatementBytes: int, sqlBytes: int, compoundProgramCount: int}
     */
    public function toArray(): array
    {
        return [
            'accepted' => $this->accepted(),
            'problems' => $this->problems,
            'warnings' => $this->warnings,
            'databases' => $this->databases,
            'sourceDatabase' => $this->sourceDatabase,
            'statementCount' => $this->statementCount,
            'tables' => $this->tables,
            'tableCount' => $this->tableCount,
            'skippedStatements' => $this->skippedStatements,
            'storedObjectCount' => $this->storedObjectCount,
            'disablesForeignKeyChecks' => $this->disablesForeignKeyChecks,
            'largestStatementBytes' => $this->largestStatementBytes,
            'sqlBytes' => $this->sqlBytes,
            'compoundProgramCount' => $this->compoundProgramCount,
        ];
    }
}

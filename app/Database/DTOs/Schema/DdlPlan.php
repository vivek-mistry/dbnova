<?php

namespace App\Database\DTOs\Schema;

/**
 * The SQL a schema change will run, shown to the user before it runs.
 */
final readonly class DdlPlan
{
    /**
     * @param  list<string>  $statements
     * @param  list<string>  $warnings
     */
    public function __construct(
        public array $statements,
        public array $warnings = [],
        public ?int $approximateRows = null,
    ) {}

    /**
     * @return array{statements: list<string>, warnings: list<string>, approximateRows: int|null}
     */
    public function toArray(): array
    {
        return [
            'statements' => $this->statements,
            'warnings' => $this->warnings,
            'approximateRows' => $this->approximateRows,
        ];
    }
}

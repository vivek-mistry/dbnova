<?php

namespace App\Database\DTOs\Schema;

final readonly class ForeignKeyDefinition
{
    /**
     * @param  list<string>  $columns
     * @param  list<string>  $referencedColumns
     */
    public function __construct(
        public array $columns,
        public string $referencedTable,
        public array $referencedColumns,
        public ForeignKeyAction $onDelete = ForeignKeyAction::Restrict,
        public ForeignKeyAction $onUpdate = ForeignKeyAction::Restrict,
        public ?string $name = null,
    ) {}
}

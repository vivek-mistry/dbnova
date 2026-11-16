<?php

namespace App\Database\DTOs\Schema;

final readonly class CreateTable implements SchemaChange
{
    /**
     * @param  list<ColumnDefinition>  $columns
     * @param  list<string>  $primaryKey
     */
    public function __construct(
        public array $columns,
        public array $primaryKey = [],
        public ?string $engine = null,
        public ?string $collation = null,
        public string $comment = '',
    ) {}

    public function action(): string
    {
        return 'schema.create_table';
    }

    public function isDestructive(): bool
    {
        return false;
    }

    public function isAlter(): bool
    {
        return false;
    }

    public function auditDetails(): array
    {
        return ['columns' => array_map(fn (ColumnDefinition $column): string => $column->name, $this->columns)];
    }
}

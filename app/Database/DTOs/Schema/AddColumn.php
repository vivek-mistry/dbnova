<?php

namespace App\Database\DTOs\Schema;

final readonly class AddColumn implements SchemaChange
{
    public function __construct(
        public ColumnDefinition $column,
        public ?string $after = null,
        public bool $first = false,
    ) {}

    public function action(): string
    {
        return 'schema.add_column';
    }

    public function isDestructive(): bool
    {
        return false;
    }

    public function isAlter(): bool
    {
        return true;
    }

    public function auditDetails(): array
    {
        return ['column' => $this->column->name];
    }
}

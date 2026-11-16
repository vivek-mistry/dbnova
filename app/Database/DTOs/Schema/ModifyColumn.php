<?php

namespace App\Database\DTOs\Schema;

final readonly class ModifyColumn implements SchemaChange
{
    /**
     * @param  string  $column  The current name; the definition may rename it.
     */
    public function __construct(
        public string $column,
        public ColumnDefinition $definition,
        public ?string $after = null,
        public bool $first = false,
    ) {}

    public function action(): string
    {
        return 'schema.modify_column';
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
        return ['column' => $this->column, 'new_name' => $this->definition->name];
    }
}

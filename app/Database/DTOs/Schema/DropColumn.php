<?php

namespace App\Database\DTOs\Schema;

final readonly class DropColumn implements SchemaChange
{
    public function __construct(public string $column) {}

    public function action(): string
    {
        return 'schema.drop_column';
    }

    public function isDestructive(): bool
    {
        return true;
    }

    public function isAlter(): bool
    {
        return true;
    }

    public function auditDetails(): array
    {
        return ['column' => $this->column];
    }
}

<?php

namespace App\Database\DTOs\Schema;

final readonly class AddIndex implements SchemaChange
{
    public function __construct(public IndexDefinition $index) {}

    public function action(): string
    {
        return 'schema.add_index';
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
        return ['type' => $this->index->type->value, 'columns' => $this->index->columns];
    }
}

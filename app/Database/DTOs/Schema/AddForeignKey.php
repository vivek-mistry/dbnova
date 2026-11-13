<?php

namespace App\Database\DTOs\Schema;

final readonly class AddForeignKey implements SchemaChange
{
    public function __construct(public ForeignKeyDefinition $foreignKey) {}

    public function action(): string
    {
        return 'schema.add_foreign_key';
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
        return ['columns' => $this->foreignKey->columns, 'references' => $this->foreignKey->referencedTable];
    }
}

<?php

namespace App\Database\DTOs\Schema;

final readonly class RenameTable implements SchemaChange
{
    public function __construct(public string $newName) {}

    public function action(): string
    {
        return 'schema.rename_table';
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
        return ['new_name' => $this->newName];
    }
}

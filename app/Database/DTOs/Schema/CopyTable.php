<?php

namespace App\Database\DTOs\Schema;

final readonly class CopyTable implements SchemaChange
{
    public function __construct(
        public string $newName,
        public bool $withData = false,
    ) {}

    public function action(): string
    {
        return 'schema.copy_table';
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
        return ['new_name' => $this->newName, 'with_data' => $this->withData];
    }
}

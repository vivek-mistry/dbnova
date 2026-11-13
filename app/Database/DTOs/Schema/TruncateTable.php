<?php

namespace App\Database\DTOs\Schema;

final readonly class TruncateTable implements SchemaChange
{
    public function action(): string
    {
        return 'schema.truncate_table';
    }

    public function isDestructive(): bool
    {
        return true;
    }

    public function isAlter(): bool
    {
        return false;
    }

    public function auditDetails(): array
    {
        return [];
    }
}

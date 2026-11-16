<?php

namespace App\Database\DTOs\Schema;

final readonly class DropIndex implements SchemaChange
{
    public function __construct(public string $name) {}

    public function action(): string
    {
        return 'schema.drop_index';
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
        return ['index' => $this->name];
    }
}

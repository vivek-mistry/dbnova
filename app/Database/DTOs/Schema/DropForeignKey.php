<?php

namespace App\Database\DTOs\Schema;

final readonly class DropForeignKey implements SchemaChange
{
    public function __construct(public string $name) {}

    public function action(): string
    {
        return 'schema.drop_foreign_key';
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
        return ['foreign_key' => $this->name];
    }
}

<?php

namespace App\Database\DTOs;

final readonly class DatabaseInfo
{
    public function __construct(
        public string $name,
        public string $characterSet,
        public string $collation,
        public int $tableCount,
        public int $sizeBytes,
        public bool $isSystem,
    ) {}

    /**
     * @return array{name: string, characterSet: string, collation: string, tableCount: int, sizeBytes: int, isSystem: bool}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'characterSet' => $this->characterSet,
            'collation' => $this->collation,
            'tableCount' => $this->tableCount,
            'sizeBytes' => $this->sizeBytes,
            'isSystem' => $this->isSystem,
        ];
    }
}

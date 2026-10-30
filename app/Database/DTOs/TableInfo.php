<?php

namespace App\Database\DTOs;

final readonly class TableInfo
{
    /**
     * @param  int|null  $approximateRows  InnoDB row counts in information_schema are estimates.
     */
    public function __construct(
        public string $name,
        public string $type,
        public ?string $engine,
        public ?int $approximateRows,
        public ?int $sizeBytes,
        public ?string $collation,
    ) {}

    /**
     * @return array{name: string, type: string, engine: string|null, approximateRows: int|null, sizeBytes: int|null, collation: string|null}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'engine' => $this->engine,
            'approximateRows' => $this->approximateRows,
            'sizeBytes' => $this->sizeBytes,
            'collation' => $this->collation,
        ];
    }
}

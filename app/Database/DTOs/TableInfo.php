<?php

namespace App\Database\DTOs;

final readonly class TableInfo
{
    /**
     * @param  int|null  $approximateRows  InnoDB row counts in information_schema are estimates.
     * @param  int|null  $dataBytes  Rows (for InnoDB, the clustered primary key).
     * @param  int|null  $indexBytes  Secondary indexes.
     * @param  int|null  $freeBytes  Allocated but unused space (DATA_FREE). OPTIMIZE TABLE can return some of it; InnoDB always keeps a few MB free.
     */
    public function __construct(
        public string $name,
        public string $type,
        public ?string $engine,
        public ?int $approximateRows,
        public ?int $sizeBytes,
        public ?string $collation,
        public ?int $dataBytes = null,
        public ?int $indexBytes = null,
        public ?int $freeBytes = null,
    ) {}

    /**
     * @return array{name: string, type: string, engine: string|null, approximateRows: int|null, sizeBytes: int|null, collation: string|null, dataBytes: int|null, indexBytes: int|null, freeBytes: int|null}
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
            'dataBytes' => $this->dataBytes,
            'indexBytes' => $this->indexBytes,
            'freeBytes' => $this->freeBytes,
        ];
    }
}

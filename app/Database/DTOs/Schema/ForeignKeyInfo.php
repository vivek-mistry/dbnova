<?php

namespace App\Database\DTOs\Schema;

final readonly class ForeignKeyInfo
{
    /**
     * @param  list<string>  $columns
     * @param  list<string>  $referencedColumns
     */
    public function __construct(
        public string $name,
        public array $columns,
        public string $referencedDatabase,
        public string $referencedTable,
        public array $referencedColumns,
        public string $onUpdate,
        public string $onDelete,
    ) {}

    /**
     * @return array{name: string, columns: list<string>, referencedDatabase: string, referencedTable: string, referencedColumns: list<string>, onUpdate: string, onDelete: string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'columns' => $this->columns,
            'referencedDatabase' => $this->referencedDatabase,
            'referencedTable' => $this->referencedTable,
            'referencedColumns' => $this->referencedColumns,
            'onUpdate' => $this->onUpdate,
            'onDelete' => $this->onDelete,
        ];
    }
}

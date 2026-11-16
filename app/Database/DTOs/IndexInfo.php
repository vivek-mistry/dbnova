<?php

namespace App\Database\DTOs;

final readonly class IndexInfo
{
    /**
     * @param  list<string>  $columns
     */
    public function __construct(
        public string $name,
        public array $columns,
        public bool $unique,
        public string $type,
    ) {}

    /**
     * @return array{name: string, columns: list<string>, unique: bool, type: string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'columns' => $this->columns,
            'unique' => $this->unique,
            'type' => $this->type,
        ];
    }
}

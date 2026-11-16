<?php

namespace App\Database\DTOs\Schema;

final readonly class IndexDefinition
{
    /**
     * @param  list<string>  $columns
     */
    public function __construct(
        public IndexType $type,
        public array $columns,
        public ?string $name = null,
    ) {}
}

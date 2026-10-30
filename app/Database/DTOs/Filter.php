<?php

namespace App\Database\DTOs;

final readonly class Filter
{
    public function __construct(
        public string $column,
        public FilterOperator $operator,
        public ?string $value = null,
    ) {}
}

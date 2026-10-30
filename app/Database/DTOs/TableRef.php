<?php

namespace App\Database\DTOs;

final readonly class TableRef
{
    public function __construct(
        public string $database,
        public string $table,
    ) {}
}

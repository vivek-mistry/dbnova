<?php

namespace App\Database\DTOs\Schema;

final readonly class ColumnDefault
{
    public function __construct(
        public ColumnDefaultKind $kind = ColumnDefaultKind::None,
        public ?string $value = null,
    ) {}
}

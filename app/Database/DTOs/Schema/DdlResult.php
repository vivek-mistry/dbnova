<?php

namespace App\Database\DTOs\Schema;

final readonly class DdlResult
{
    /**
     * @param  list<string>  $statements  The statements that ran.
     * @param  'INSTANT'|'INPLACE'|'COPY'|null  $algorithm  How MySQL ran an ALTER TABLE, or null for other statements.
     */
    public function __construct(
        public array $statements,
        public ?string $algorithm = null,
    ) {}
}

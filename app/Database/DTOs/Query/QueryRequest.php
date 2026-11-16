<?php

namespace App\Database\DTOs\Query;

final readonly class QueryRequest
{
    /**
     * @param  string|null  $database  The default database for the statements (like USE).
     * @param  bool  $confirmedDestructive  Whether the user confirmed DROP, TRUNCATE and unfiltered UPDATE/DELETE.
     * @param  int  $rowCap  Most rows returned per result set.
     */
    public function __construct(
        public string $sql,
        public ?string $database = null,
        public bool $confirmedDestructive = false,
        public int $rowCap = 1000,
    ) {}
}

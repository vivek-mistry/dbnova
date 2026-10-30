<?php

namespace App\Database\DTOs;

final readonly class PageRequest
{
    public const int MAX_LIMIT = 1000;

    /**
     * @param  'asc'|'desc'  $direction
     * @param  list<Filter>  $filters
     * @param  list<int|float|string|null>|null  $cursor  Key values of the last row of the previous page (keyset paging).
     */
    public function __construct(
        public int $limit,
        public ?string $sort = null,
        public string $direction = 'asc',
        public array $filters = [],
        public ?array $cursor = null,
        public int $offset = 0,
    ) {}
}

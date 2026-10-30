<?php

namespace App\Database\DTOs;

/**
 * One page of table rows. Each row is a list of cells in column order. A cell
 * is a scalar, null, ['binary' => byteLength] for binary data, or
 * ['preview' => text, 'truncated' => true] for text longer than the preview.
 */
final readonly class Page
{
    /**
     * @param  list<list<mixed>>  $rows
     * @param  'keyset'|'offset'  $strategy
     * @param  list<int|float|string|null>|null  $nextCursor
     */
    public function __construct(
        public array $rows,
        public bool $hasMore,
        public string $strategy,
        public ?array $nextCursor,
        public ?int $nextOffset,
        public float $durationMs,
    ) {}

    /**
     * @return array{rows: list<list<mixed>>, hasMore: bool, strategy: string, nextCursor: string|null, nextOffset: int|null, durationMs: float}
     */
    public function toArray(): array
    {
        return [
            'rows' => $this->rows,
            'hasMore' => $this->hasMore,
            'strategy' => $this->strategy,
            'nextCursor' => $this->nextCursor === null ? null : self::encodeCursor($this->nextCursor),
            'nextOffset' => $this->nextOffset,
            'durationMs' => round($this->durationMs, 1),
        ];
    }

    /**
     * @param  list<int|float|string|null>  $cursor
     */
    public static function encodeCursor(array $cursor): string
    {
        return base64_encode(json_encode($cursor, JSON_THROW_ON_ERROR));
    }

    /**
     * @return list<int|float|string|null>|null
     */
    public static function decodeCursor(string $encoded): ?array
    {
        $json = base64_decode($encoded, true);

        if ($json === false) {
            return null;
        }

        $cursor = json_decode($json, true);

        if (! is_array($cursor) || ! array_is_list($cursor)) {
            return null;
        }

        foreach ($cursor as $value) {
            if ($value !== null && ! is_scalar($value)) {
                return null;
            }
        }

        /** @var list<int|float|string|null> $cursor */
        return $cursor;
    }
}

<?php

namespace App\Database\Support;

/**
 * Turns a value read from MySQL into something JSON and the grid can show:
 * binary data becomes its byte length, long text a preview, and integers
 * beyond JavaScript's precision become strings.
 */
class CellEncoder
{
    private const int MAX_SAFE_INTEGER = 9007199254740991;

    public function encode(mixed $value, int $previewLength = 256): mixed
    {
        return match (true) {
            $value === null => null,
            is_int($value) => abs($value) > self::MAX_SAFE_INTEGER ? (string) $value : $value,
            ! is_string($value) => $value,
            ! mb_check_encoding($value, 'UTF-8') => ['binary' => strlen($value)],
            mb_strlen($value) > $previewLength => ['preview' => mb_substr($value, 0, $previewLength), 'truncated' => true],
            default => $value,
        };
    }
}

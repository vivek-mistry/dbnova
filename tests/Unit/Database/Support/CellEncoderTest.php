<?php

use App\Database\Support\CellEncoder;

test('values become grid cells', function (mixed $value, mixed $expected) {
    expect((new CellEncoder)->encode($value, previewLength: 5))->toBe($expected);
})->with([
    'null' => [null, null],
    'small integer' => [42, 42],
    'integer beyond JavaScript precision' => [PHP_INT_MAX, (string) PHP_INT_MAX],
    'float' => [1.5, 1.5],
    'short text' => ['hello', 'hello'],
    'empty text' => ['', ''],
    'long text' => ['héllo world', ['preview' => 'héllo', 'truncated' => true]],
    'binary' => ["\x00\xFF\xFE", ['binary' => 3]],
]);

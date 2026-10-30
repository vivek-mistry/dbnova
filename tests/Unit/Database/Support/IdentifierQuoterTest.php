<?php

use App\Database\Exceptions\InvalidIdentifierException;
use App\Database\Support\IdentifierQuoter;

test('identifiers are wrapped in backticks', function (string $identifier, string $expected) {
    expect((new IdentifierQuoter)->quote($identifier))->toBe($expected);
})->with([
    'plain' => ['shop', '`shop`'],
    'with spaces' => ['my shop', '`my shop`'],
    'backticks are doubled' => ['evil`; DROP DATABASE x; --', '`evil``; DROP DATABASE x; --`'],
    'unicode' => ['café_données', '`café_données`'],
    'sixty-four characters' => [str_repeat('a', 64), '`'.str_repeat('a', 64).'`'],
]);

test('invalid identifiers are rejected', function (string $identifier, string $message) {
    expect(fn () => (new IdentifierQuoter)->quote($identifier))
        ->toThrow(InvalidIdentifierException::class, $message);
})->with([
    'empty' => ['', 'Names cannot be empty.'],
    'NUL byte' => ["shop\0", 'Names cannot contain NUL characters.'],
    'too long' => [str_repeat('a', 65), 'Names cannot be longer than 64 characters.'],
]);

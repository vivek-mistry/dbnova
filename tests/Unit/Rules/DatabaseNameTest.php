<?php

use App\Rules\DatabaseName;

/**
 * @return list<string>
 */
function databaseNameFailures(mixed $value): array
{
    $failures = [];

    (new DatabaseName)->validate('name', $value, function (string $message) use (&$failures) {
        $failures[] = $message;
    });

    return $failures;
}

test('valid database names pass', function (string $name) {
    expect(databaseNameFailures($name))->toBe([]);
})->with([
    'snake case' => ['my_app'],
    'with dash and space' => ['my-app test'],
    'unicode' => ['données'],
    'sixty-four characters' => [str_repeat('a', 64)],
]);

test('invalid database names fail with a reason', function (mixed $name, string $message) {
    expect(databaseNameFailures($name))->toContain($message);
})->with([
    'too long' => [str_repeat('a', 65), 'The :attribute cannot be longer than 64 characters.'],
    'slash' => ['a/b', 'The :attribute cannot contain "/", "\\", "." or NUL characters.'],
    'backslash' => ['a\\b', 'The :attribute cannot contain "/", "\\", "." or NUL characters.'],
    'dot' => ['a.b', 'The :attribute cannot contain "/", "\\", "." or NUL characters.'],
    'trailing space' => ['shop ', 'The :attribute cannot end with a space.'],
    'system database' => ['MySQL', 'The :attribute cannot be the name of a MySQL system database.'],
    'not text' => [123, 'The :attribute must be text.'],
]);

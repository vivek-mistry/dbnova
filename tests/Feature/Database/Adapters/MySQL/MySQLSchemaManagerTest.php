<?php

use App\Database\Adapters\MySQL\MySQLConnection;
use App\Database\Adapters\MySQL\MySQLSchemaManager;
use App\Database\Exceptions\DatabaseOperationException;
use App\Database\Exceptions\ReadOnlyModeException;
use Mockery\MockInterface;

/**
 * These tests check the SQL the schema manager sends without a MySQL server.
 * tests/Feature/Database/MySQL covers the same behaviour against a real one.
 */
beforeEach(function () {
    $this->connection = Mockery::mock(MySQLConnection::class);
    $this->app->instance(MySQLConnection::class, $this->connection);
});

function databaseRow(string $name, int $tables = 0): array
{
    return [
        'SCHEMA_NAME' => $name,
        'DEFAULT_CHARACTER_SET_NAME' => 'utf8mb4',
        'DEFAULT_COLLATION_NAME' => 'utf8mb4_0900_ai_ci',
        'TABLE_COUNT' => $tables,
        'SIZE_BYTES' => 16384,
    ];
}

function expectDatabaseLookup(MockInterface $connection, string $name, ?array $row): void
{
    $connection->shouldReceive('select')
        ->withArgs(fn (string $sql, array $bindings = []) => str_contains($sql, 'information_schema.SCHEMATA') && $bindings === [$name])
        ->andReturn($row === null ? [] : [$row]);
}

function expectNoBlockers(MockInterface $connection): void
{
    $connection->shouldReceive('select')
        ->withArgs(fn (string $sql) => str_contains($sql, 'information_schema.VIEWS'))
        ->andReturn([['views' => 0, 'routines' => 0, 'events' => 0, 'triggers' => 0]]);
}

describe('read-only mode', function () {
    beforeEach(function () {
        config(['dbnova.read_only' => true]);
        $this->connection->shouldNotReceive('statement');
    });

    test('creating a database is refused', function () {
        expect(fn () => app(MySQLSchemaManager::class)->createDatabase('shop'))->toThrow(ReadOnlyModeException::class);
    });

    test('renaming a database is refused', function () {
        expect(fn () => app(MySQLSchemaManager::class)->renameDatabase('shop', 'store'))->toThrow(ReadOnlyModeException::class);
    });

    test('dropping a database is refused', function () {
        expect(fn () => app(MySQLSchemaManager::class)->dropDatabase('shop'))->toThrow(ReadOnlyModeException::class);
    });
});

test('creating a database quotes its name', function () {
    $this->connection->shouldReceive('statement')->once()->with('CREATE DATABASE `my``shop`');

    app(MySQLSchemaManager::class)->createDatabase('my`shop');
});

test('creating a database with a collation checks the collation exists on the server', function () {
    $this->connection->shouldReceive('scalar')
        ->with('SELECT COUNT(*) FROM information_schema.COLLATIONS WHERE COLLATION_NAME = ?', ['utf8mb4_unicode_ci'])
        ->andReturn(1);
    $this->connection->shouldReceive('statement')->once()->with('CREATE DATABASE `shop` COLLATE utf8mb4_unicode_ci');

    app(MySQLSchemaManager::class)->createDatabase('shop', 'utf8mb4_unicode_ci');
});

test('an unknown collation is rejected before any statement runs', function () {
    $this->connection->shouldReceive('scalar')->andReturn(0);
    $this->connection->shouldNotReceive('statement');

    expect(fn () => app(MySQLSchemaManager::class)->createDatabase('shop', 'made_up_ci'))
        ->toThrow(DatabaseOperationException::class, 'The collation "made_up_ci" is not available on this server.');
});

test('system databases cannot be dropped', function () {
    $this->connection->shouldNotReceive('statement');

    expect(fn () => app(MySQLSchemaManager::class)->dropDatabase('mysql'))
        ->toThrow(DatabaseOperationException::class, '"mysql" is a MySQL system database and cannot be changed.');
});

test('renaming moves every table in one RENAME TABLE statement and drops the old database', function () {
    expectDatabaseLookup($this->connection, 'shop', databaseRow('shop', 2));
    expectDatabaseLookup($this->connection, 'store', null);
    expectNoBlockers($this->connection);
    $this->connection->shouldReceive('scalar')->andReturn(1);
    $this->connection->shouldReceive('select')
        ->withArgs(fn (string $sql) => str_contains($sql, 'FROM information_schema.TABLES'))
        ->andReturn([
            ['TABLE_NAME' => 'orders', 'TABLE_TYPE' => 'BASE TABLE', 'ENGINE' => 'InnoDB', 'TABLE_ROWS' => 1, 'SIZE_BYTES' => 1, 'TABLE_COLLATION' => null],
            ['TABLE_NAME' => 'users', 'TABLE_TYPE' => 'BASE TABLE', 'ENGINE' => 'InnoDB', 'TABLE_ROWS' => 1, 'SIZE_BYTES' => 1, 'TABLE_COLLATION' => null],
        ]);

    $this->connection->shouldReceive('statement')->once()->ordered()->with('CREATE DATABASE `store` COLLATE utf8mb4_0900_ai_ci');
    $this->connection->shouldReceive('statement')->once()->ordered()->with('RENAME TABLE `shop`.`orders` TO `store`.`orders`, `shop`.`users` TO `store`.`users`');
    $this->connection->shouldReceive('statement')->once()->ordered()->with('DROP DATABASE `shop`');

    app(MySQLSchemaManager::class)->renameDatabase('shop', 'store');
});

test('renaming is refused when the database has objects that cannot be moved', function () {
    expectDatabaseLookup($this->connection, 'shop', databaseRow('shop', 2));
    expectDatabaseLookup($this->connection, 'store', null);
    $this->connection->shouldReceive('select')
        ->withArgs(fn (string $sql) => str_contains($sql, 'information_schema.VIEWS'))
        ->andReturn([['views' => 1, 'routines' => 0, 'events' => 0, 'triggers' => 2]]);
    $this->connection->shouldNotReceive('statement');

    expect(fn () => app(MySQLSchemaManager::class)->renameDatabase('shop', 'store'))
        ->toThrow(DatabaseOperationException::class, '"shop" cannot be renamed because it contains 1 view, 2 triggers. Only databases with plain tables can be renamed.');
});

test('renaming is refused when the new name is taken', function () {
    expectDatabaseLookup($this->connection, 'shop', databaseRow('shop'));
    expectDatabaseLookup($this->connection, 'store', databaseRow('store'));
    $this->connection->shouldNotReceive('statement');

    expect(fn () => app(MySQLSchemaManager::class)->renameDatabase('shop', 'store'))
        ->toThrow(DatabaseOperationException::class, 'A database named "store" already exists.');
});

test('a failed table move drops the new empty database and keeps the original', function () {
    expectDatabaseLookup($this->connection, 'shop', databaseRow('shop', 1));
    expectDatabaseLookup($this->connection, 'store', null);
    expectNoBlockers($this->connection);
    $this->connection->shouldReceive('scalar')->andReturn(1);
    $this->connection->shouldReceive('select')
        ->withArgs(fn (string $sql) => str_contains($sql, 'FROM information_schema.TABLES'))
        ->andReturn([['TABLE_NAME' => 'orders', 'TABLE_TYPE' => 'BASE TABLE', 'ENGINE' => 'InnoDB', 'TABLE_ROWS' => 1, 'SIZE_BYTES' => 1, 'TABLE_COLLATION' => null]]);

    $this->connection->shouldReceive('statement')->once()->ordered()->with('CREATE DATABASE `store` COLLATE utf8mb4_0900_ai_ci');
    $this->connection->shouldReceive('statement')->once()->ordered()
        ->with('RENAME TABLE `shop`.`orders` TO `store`.`orders`')
        ->andThrow(new DatabaseOperationException('MySQL error: Lock wait timeout exceeded'));
    $this->connection->shouldReceive('statement')->once()->ordered()->with('DROP DATABASE `store`');
    $this->connection->shouldNotReceive('statement')->with('DROP DATABASE `shop`');

    expect(fn () => app(MySQLSchemaManager::class)->renameDatabase('shop', 'store'))
        ->toThrow(DatabaseOperationException::class, 'MySQL error: Lock wait timeout exceeded');
});

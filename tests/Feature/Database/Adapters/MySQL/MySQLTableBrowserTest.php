<?php

use App\Database\Adapters\MySQL\MySQLConnection;
use App\Database\Adapters\MySQL\MySQLTableBrowser;
use App\Database\DTOs\PageRequest;
use App\Database\DTOs\TableRef;

/**
 * How the browser turns MySQL rows into page cells, without a MySQL server.
 */
beforeEach(function () {
    $this->connection = Mockery::mock(MySQLConnection::class);
    $this->app->instance(MySQLConnection::class, $this->connection);

    $this->connection->shouldReceive('select')
        ->withArgs(fn (string $sql) => str_contains($sql, 'information_schema.COLUMNS'))
        ->andReturn([
            ['COLUMN_NAME' => 'id', 'DATA_TYPE' => 'bigint', 'COLUMN_TYPE' => 'bigint unsigned', 'IS_NULLABLE' => 'NO', 'COLUMN_KEY' => 'PRI', 'COLUMN_DEFAULT' => null, 'EXTRA' => 'auto_increment', 'CHARACTER_MAXIMUM_LENGTH' => null, 'COLUMN_COMMENT' => ''],
            ['COLUMN_NAME' => 'notes', 'DATA_TYPE' => 'text', 'COLUMN_TYPE' => 'text', 'IS_NULLABLE' => 'YES', 'COLUMN_KEY' => '', 'COLUMN_DEFAULT' => null, 'EXTRA' => '', 'CHARACTER_MAXIMUM_LENGTH' => 65535, 'COLUMN_COMMENT' => ''],
            ['COLUMN_NAME' => 'attachment', 'DATA_TYPE' => 'blob', 'COLUMN_TYPE' => 'blob', 'IS_NULLABLE' => 'YES', 'COLUMN_KEY' => '', 'COLUMN_DEFAULT' => null, 'EXTRA' => '', 'CHARACTER_MAXIMUM_LENGTH' => 65535, 'COLUMN_COMMENT' => ''],
        ]);
    $this->connection->shouldReceive('select')
        ->withArgs(fn (string $sql) => str_contains($sql, 'information_schema.STATISTICS'))
        ->andReturn([['INDEX_NAME' => 'PRIMARY', 'COLUMN_NAME' => 'id', 'NON_UNIQUE' => 0, 'INDEX_TYPE' => 'BTREE']]);
});

function pageRow(int|string $id, ?string $notes, ?int $attachmentBytes): array
{
    return ['c0' => $id, 'c1' => $notes, 'c2' => $attachmentBytes, 'k0' => $id];
}

test('rows become cells: NULL, binary length, truncated text and plain values', function () {
    $this->connection->shouldReceive('select')
        ->withArgs(fn (string $sql) => str_starts_with($sql, 'SELECT `id` AS c0'))
        ->andReturn([
            pageRow(1, '', null),
            pageRow(2, str_repeat('é', 257), 2048),
            pageRow(3, null, null),
        ]);

    $page = app(MySQLTableBrowser::class)->page(new TableRef('shop', 'orders'), new PageRequest(limit: 10));

    expect($page->rows)->toBe([
        [1, '', null],
        [2, ['preview' => str_repeat('é', 256), 'truncated' => true], ['binary' => 2048]],
        [3, null, null],
    ]);
    expect($page->hasMore)->toBeFalse();
    expect($page->nextCursor)->toBeNull();
});

test('a full extra row means there is a next page whose cursor is the last returned key', function () {
    $this->connection->shouldReceive('select')
        ->withArgs(fn (string $sql) => str_starts_with($sql, 'SELECT `id` AS c0'))
        ->andReturn([pageRow(1, 'a', null), pageRow(2, 'b', null), pageRow(3, 'c', null)]);

    $page = app(MySQLTableBrowser::class)->page(new TableRef('shop', 'orders'), new PageRequest(limit: 2));

    expect($page->rows)->toHaveCount(2);
    expect($page->hasMore)->toBeTrue();
    expect($page->strategy)->toBe('keyset');
    expect($page->nextCursor)->toBe([2]);
});

test('integers beyond JavaScript precision are sent as strings', function () {
    $this->connection->shouldReceive('select')
        ->withArgs(fn (string $sql) => str_starts_with($sql, 'SELECT `id` AS c0'))
        ->andReturn([pageRow(PHP_INT_MAX, null, null)]);

    $page = app(MySQLTableBrowser::class)->page(new TableRef('shop', 'orders'), new PageRequest(limit: 10));

    expect($page->rows[0][0])->toBe((string) PHP_INT_MAX);
});

test('full values are located by key: text as text, binary as a hex preview', function () {
    $this->connection->shouldReceive('scalar')
        ->with('SELECT `notes` FROM `shop`.`orders` WHERE `id` = ? LIMIT 1', [2])
        ->andReturn('long text');
    $this->connection->shouldReceive('scalar')
        ->with('SELECT `attachment` FROM `shop`.`orders` WHERE `id` = ? LIMIT 1', [2])
        ->andReturn("\x00\xFF".str_repeat('a', 2000));

    $browser = app(MySQLTableBrowser::class);

    expect($browser->value(new TableRef('shop', 'orders'), 'notes', [2]))->toBe(['text' => 'long text']);
    expect($browser->value(new TableRef('shop', 'orders'), 'attachment', [2]))
        ->toBe(['bytes' => 2002, 'hex' => '00ff'.str_repeat('61', 1022)]);
});

<?php

use App\Database\Adapters\MySQL\MySQLConnection;
use App\Database\Adapters\MySQL\MySQLRecordManager;
use App\Database\Adapters\MySQL\MySQLTableBrowser;
use App\Database\DTOs\ColumnInfo;
use App\Database\DTOs\TableRef;
use App\Database\Exceptions\DatabaseOperationException;
use App\Database\Exceptions\ReadOnlyModeException;
use Mockery\MockInterface;

/**
 * The SQL the record manager sends, without a MySQL server.
 */
beforeEach(function () {
    $this->connection = Mockery::mock(MySQLConnection::class);
    $this->browser = Mockery::mock(MySQLTableBrowser::class);
    $this->app->instance(MySQLConnection::class, $this->connection);
    $this->app->instance(MySQLTableBrowser::class, $this->browser);

    $this->browser->shouldReceive('columns')->andReturn([
        new ColumnInfo('id', 'int', 'int', false, 'PRI', null, 'auto_increment', null, ''),
        new ColumnInfo('notes', 'text', 'text', true, '', null, '', 65535, ''),
        new ColumnInfo('photo', 'blob', 'blob', true, '', null, '', 65535, ''),
        new ColumnInfo('total', 'decimal', 'decimal(10,2)', true, '', null, 'STORED GENERATED', null, ''),
    ]);
    $this->orders = new TableRef('shop', 'orders');
});

function withKey(MockInterface $browser, array $keyColumns = ['id']): void
{
    $browser->shouldReceive('keyColumns')->andReturn($keyColumns);
}

describe('read-only mode', function () {
    beforeEach(function () {
        config(['dbnova.read_only' => true]);
        $this->connection->shouldNotReceive('statement', 'affectingStatement', 'transaction');
    });

    test('inserting is refused', function () {
        expect(fn () => app(MySQLRecordManager::class)->insert($this->orders, ['notes' => 'x']))->toThrow(ReadOnlyModeException::class);
    });

    test('updating is refused', function () {
        expect(fn () => app(MySQLRecordManager::class)->update($this->orders, [1], ['notes' => 'x']))->toThrow(ReadOnlyModeException::class);
    });

    test('deleting is refused', function () {
        expect(fn () => app(MySQLRecordManager::class)->delete($this->orders, [[1]]))->toThrow(ReadOnlyModeException::class);
    });
});

test('tables without a key cannot be updated or deleted from', function (Closure $operation) {
    withKey($this->browser, []);
    $this->connection->shouldNotReceive('affectingStatement', 'transaction');

    expect(fn () => $operation(app(MySQLRecordManager::class), $this->orders))
        ->toThrow(DatabaseOperationException::class, 'has no primary or unique key');
})->with([
    'update' => [fn (MySQLRecordManager $records, TableRef $table) => $records->update($table, [1], ['notes' => 'x'])],
    'delete' => [fn (MySQLRecordManager $records, TableRef $table) => $records->delete($table, [[1]])],
]);

test('columns that cannot be set are refused', function (string $column, string $message) {
    withKey($this->browser);
    $this->connection->shouldNotReceive('affectingStatement');

    expect(fn () => app(MySQLRecordManager::class)->update($this->orders, [1], [$column => 'x']))
        ->toThrow(DatabaseOperationException::class, $message);
})->with([
    'binary' => ['photo', '"photo" holds binary data'],
    'generated' => ['total', '"total" is a generated column'],
    'unknown' => ['missing', 'Unknown column "missing".'],
]);

test('an update sets the values, matches the key and touches one row', function () {
    withKey($this->browser);
    $this->connection->shouldReceive('affectingStatement')
        ->once()
        ->with('UPDATE `shop`.`orders` SET `notes` = ? WHERE `id` = ? LIMIT 1', [null, 7])
        ->andReturn(1);

    $newKey = app(MySQLRecordManager::class)->update($this->orders, [7], ['notes' => null]);

    expect($newKey)->toBe([7]);
});

test('editing a key column returns the new key', function () {
    withKey($this->browser);
    $this->connection->shouldReceive('affectingStatement')->andReturn(1);

    expect(app(MySQLRecordManager::class)->update($this->orders, [7], ['id' => '70']))->toBe(['70']);
});

test('an update that matches no row reports that the row is gone', function () {
    withKey($this->browser);
    $this->connection->shouldReceive('affectingStatement')->andReturn(0);

    expect(fn () => app(MySQLRecordManager::class)->update($this->orders, [7], ['notes' => 'x']))
        ->toThrow(DatabaseOperationException::class, 'The row no longer exists.');
});

test('a key with the wrong number of values is refused', function () {
    withKey($this->browser, ['order_id', 'line']);
    $this->connection->shouldNotReceive('affectingStatement');

    expect(fn () => app(MySQLRecordManager::class)->update($this->orders, [7], ['notes' => 'x']))
        ->toThrow(DatabaseOperationException::class, 'The row key does not match the table key.');
});

test('deleting removes each keyed row in one transaction', function () {
    withKey($this->browser, ['order_id', 'line']);
    $this->connection->shouldReceive('transaction')->once()->andReturnUsing(fn (callable $callback) => $callback());
    $this->connection->shouldReceive('affectingStatement')
        ->once()
        ->with(
            'DELETE FROM `shop`.`orders` WHERE (`order_id` = ? AND `line` = ?) OR (`order_id` = ? AND `line` = ?)',
            [7, 1, 7, 2],
        )
        ->andReturn(2);

    expect(app(MySQLRecordManager::class)->delete($this->orders, [[7, 1], [7, 2]]))->toBe(2);
});

test('inserting sends only the given columns so the rest use their defaults', function () {
    $this->connection->shouldReceive('statement')->once()->with('INSERT INTO `shop`.`orders` (`notes`) VALUES (?)', ['']);

    app(MySQLRecordManager::class)->insert($this->orders, ['notes' => '']);
});

test('inserting with no values creates a row of defaults', function () {
    $this->connection->shouldReceive('statement')->once()->with('INSERT INTO `shop`.`orders` () VALUES ()', []);

    app(MySQLRecordManager::class)->insert($this->orders, []);
});

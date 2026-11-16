<?php

use App\Database\Adapters\MySQL\MySQLConnection;
use App\Database\Contracts\DatabaseAdapterInterface;
use App\Database\DTOs\TableRef;
use App\Database\Exceptions\DatabaseOperationException;
use App\Models\User;

/**
 * Runs against a real MySQL server when DBNOVA_TEST_DB_USERNAME is set.
 */
beforeEach(function () {
    $credentials = mySqlTestCredentials();

    if ($credentials === null) {
        $this->markTestSkipped('Set DBNOVA_TEST_DB_USERNAME in .env to run MySQL integration tests.');
    }

    actingAsMySqlUser(User::factory()->create(['username' => $credentials['username']]), $credentials['password']);

    $this->connection = app(MySQLConnection::class);
    $this->records = app(DatabaseAdapterInterface::class)->records();
    $this->browser = app(DatabaseAdapterInterface::class)->browser();
    $this->database = 'dbnova_test_'.bin2hex(random_bytes(4));

    $this->connection->statement("CREATE DATABASE `{$this->database}`");
    $this->connection->statement("CREATE TABLE `{$this->database}`.`orders` (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        status VARCHAR(20) NOT NULL DEFAULT 'new',
        notes TEXT NULL
    )");
    $this->connection->statement("INSERT INTO `{$this->database}`.`orders` (status, notes) VALUES ('new', 'a'), ('paid', 'b'), ('new', 'c')");

    $this->orders = new TableRef($this->database, 'orders');
})->group('mysql');

afterEach(function () {
    if (isset($this->connection, $this->database)) {
        $this->connection->statement("DROP DATABASE IF EXISTS `{$this->database}`");
    }
});

test('an update changes only the targeted row', function () {
    $this->records->update($this->orders, [2], ['notes' => '  kept spaces  ']);

    expect($this->browser->row($this->orders, [2]))->toBe([2, 'paid', '  kept spaces  ']);
    expect($this->browser->row($this->orders, [1]))->toBe([1, 'new', 'a']);
    expect($this->browser->row($this->orders, [3]))->toBe([3, 'new', 'c']);
});

test('saving an unchanged value still succeeds', function () {
    expect($this->records->update($this->orders, [1], ['status' => 'new']))->toBe([1]);
});

test('updating a deleted row reports that it no longer exists', function () {
    $this->records->delete($this->orders, [[1]]);

    expect(fn () => $this->records->update($this->orders, [1], ['status' => 'paid']))
        ->toThrow(DatabaseOperationException::class, 'The row no longer exists.');
});

test('deleting removes only the given rows', function () {
    expect($this->records->delete($this->orders, [[1], [3]]))->toBe(2);
    expect($this->browser->count($this->orders))->toBe(1);
    expect($this->browser->row($this->orders, [2]))->not->toBeNull();
});

test('inserting uses defaults for omitted columns and keeps NULL distinct from empty text', function () {
    $this->records->insert($this->orders, ['notes' => null]);
    $this->records->insert($this->orders, ['notes' => '']);

    expect($this->browser->row($this->orders, [4]))->toBe([4, 'new', null]);
    expect($this->browser->row($this->orders, [5]))->toBe([5, 'new', '']);
});

test('MySQL constraint errors surface with the server message', function () {
    expect(fn () => $this->records->update($this->orders, [1], ['status' => null]))
        ->toThrow(DatabaseOperationException::class, "Column 'status' cannot be null");
});

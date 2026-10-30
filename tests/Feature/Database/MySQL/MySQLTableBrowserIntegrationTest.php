<?php

use App\Database\Adapters\MySQL\MySQLConnection;
use App\Database\Contracts\DatabaseAdapterInterface;
use App\Database\DTOs\Filter;
use App\Database\DTOs\FilterOperator;
use App\Database\DTOs\PageRequest;
use App\Database\DTOs\TableRef;
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
    $this->browser = app(DatabaseAdapterInterface::class)->browser();
    $this->database = 'dbnova_test_'.bin2hex(random_bytes(4));

    $this->connection->statement("CREATE DATABASE `{$this->database}`");
    $this->connection->statement("CREATE TABLE `{$this->database}`.`orders` (
        id INT UNSIGNED NOT NULL PRIMARY KEY,
        status VARCHAR(20) NULL,
        notes TEXT NULL,
        attachment BLOB NULL
    )");
    $this->connection->statement("INSERT INTO `{$this->database}`.`orders`
        SELECT n, ELT(1 + n % 3, 'new', 'paid', NULL), IF(n = 1, REPEAT('x', 1000), ''), IF(n = 2, UNHEX('00FF10'), NULL)
        FROM (WITH RECURSIVE seq (n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM seq WHERE n < 250) SELECT n FROM seq) numbers");
    $this->connection->statement("CREATE TABLE `{$this->database}`.`log_lines` (message VARCHAR(20) NOT NULL)");
    $this->connection->statement("INSERT INTO `{$this->database}`.`log_lines` VALUES ('a'), ('b'), ('c')");

    $this->orders = new TableRef($this->database, 'orders');
})->group('mysql');

afterEach(function () {
    if (isset($this->connection, $this->database)) {
        $this->connection->statement("DROP DATABASE IF EXISTS `{$this->database}`");
    }
});

test('keyset pages cover every row exactly once', function () {
    $ids = [];
    $cursor = null;

    do {
        $page = $this->browser->page($this->orders, new PageRequest(limit: 100, cursor: $cursor));
        array_push($ids, ...array_column($page->rows, 0));
        $cursor = $page->nextCursor;
    } while ($page->hasMore);

    expect($page->strategy)->toBe('keyset');
    expect($ids)->toBe(range(1, 250));
});

test('descending keyset pages run backwards from the end', function () {
    $first = $this->browser->page($this->orders, new PageRequest(limit: 3, sort: 'id', direction: 'desc'));
    $second = $this->browser->page($this->orders, new PageRequest(limit: 3, sort: 'id', direction: 'desc', cursor: $first->nextCursor));

    expect(array_column($first->rows, 0))->toBe([250, 249, 248]);
    expect(array_column($second->rows, 0))->toBe([247, 246, 245]);
});

test('filters, counts and NULL checks use the real data', function () {
    $paid = [new Filter('status', FilterOperator::Equals, 'paid')];
    $missingStatus = [new Filter('status', FilterOperator::IsNull)];

    expect($this->browser->count($this->orders))->toBe(250);
    expect($this->browser->count($this->orders, $paid))->toBe(84);
    expect($this->browser->count($this->orders, $missingStatus))->toBe(83);
    expect($this->browser->page($this->orders, new PageRequest(limit: 5, filters: $paid))->rows[0][1])->toBe('paid');
});

test('long text is previewed, binary is described and both load in full by key', function () {
    $rows = $this->browser->page($this->orders, new PageRequest(limit: 2))->rows;

    expect($rows[0][2])->toBe(['preview' => str_repeat('x', 256), 'truncated' => true]);
    expect($rows[0][3])->toBeNull();
    expect($rows[1][2])->toBe('');
    expect($rows[1][3])->toBe(['binary' => 3]);
    expect($this->browser->value($this->orders, 'notes', [1]))->toBe(['text' => str_repeat('x', 1000)]);
    expect($this->browser->value($this->orders, 'attachment', [2]))->toBe(['bytes' => 3, 'hex' => '00ff10']);
});

test('tables without a key page by offset', function () {
    $logLines = new TableRef($this->database, 'log_lines');

    $first = $this->browser->page($logLines, new PageRequest(limit: 2));
    $second = $this->browser->page($logLines, new PageRequest(limit: 2, offset: $first->nextOffset));

    expect($this->browser->keyColumns($logLines))->toBe([]);
    expect($first->strategy)->toBe('offset');
    expect($first->rows)->toHaveCount(2);
    expect($second->rows)->toHaveCount(1);
    expect($second->hasMore)->toBeFalse();
});

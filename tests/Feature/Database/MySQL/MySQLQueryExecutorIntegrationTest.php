<?php

use App\Database\Adapters\MySQL\MySQLConnection;
use App\Database\Contracts\DatabaseAdapterInterface;
use App\Database\DTOs\Query\QueryRequest;
use App\Database\Exceptions\DestructiveStatementsException;
use App\Database\Exceptions\ReadOnlyModeException;
use App\Models\User;

/**
 * Runs against a real MySQL server when DBNOVA_TEST_DB_USERNAME is set.
 */
beforeEach(function () {
    $this->credentials = mySqlTestCredentials();

    if ($this->credentials === null) {
        $this->markTestSkipped('Set DBNOVA_TEST_DB_USERNAME in .env to run MySQL integration tests.');
    }

    actingAsMySqlUser(User::factory()->create(['username' => $this->credentials['username']]), $this->credentials['password']);

    $this->connection = app(MySQLConnection::class);
    $this->queries = app(DatabaseAdapterInterface::class)->queries();
    $this->database = 'dbnova_test_'.bin2hex(random_bytes(4));
    $this->connection->statement("CREATE DATABASE `{$this->database}`");
    $this->connection->statement("CREATE TABLE `{$this->database}`.`numbers` (id INT AUTO_INCREMENT PRIMARY KEY, n INT NOT NULL)");
    $this->connection->statement("INSERT INTO `{$this->database}`.`numbers` (n) WITH RECURSIVE seq (n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM seq WHERE n < 50) SELECT n FROM seq");

    $this->run = fn (string $sql, array $options = []) => $this->queries->run(new QueryRequest($sql, $this->database, ...$options));
})->group('mysql');

afterEach(function () {
    if (isset($this->connection, $this->database)) {
        $this->connection->statement("DROP DATABASE IF EXISTS `{$this->database}`");
    }
});

test('results are capped and the connection keeps working afterwards', function () {
    $run = ($this->run)('SELECT * FROM numbers ORDER BY id; SELECT COUNT(*) FROM numbers', ['rowCap' => 10]);

    expect($run->statements[0]->rows)->toHaveCount(10);
    expect($run->statements[0]->rowsTruncated)->toBeTrue();
    expect($run->statements[0]->columns)->toBe(['id', 'n']);
    expect($run->statements[1]->rows)->toBe([[50]]);
    expect($run->succeeded())->toBeTrue();
});

test('the first error stops the run, earlier statements stay applied', function () {
    $run = ($this->run)("UPDATE numbers SET n = 0 WHERE id = 1;\nSELECT * FROM missing;\nUPDATE numbers SET n = 0 WHERE id = 2");

    expect(array_map(fn ($statement) => $statement->status, $run->statements))->toBe(['ok', 'error', 'skipped']);
    expect($run->statements[1]->line)->toBe(2);
    expect($run->statements[1]->errorCode)->toBe(1146);
    expect($run->statements[1]->error)->toContain("missing' doesn't exist");
    expect(($this->run)('SELECT n FROM numbers WHERE id IN (1, 2) ORDER BY id')->statements[0]->rows)->toBe([[0], [2]]);
});

test('affected rows, insert ids and warnings are reported', function () {
    $run = ($this->run)("INSERT INTO numbers (n) VALUES (100), (101); UPDATE numbers SET n = n + 1 WHERE n > 99; SELECT CAST('12abc' AS UNSIGNED)");

    expect($run->statements[0]->affectedRows)->toBe(2);
    // InnoDB may reserve auto-increment ids in batches, so compare with the id MySQL actually assigned.
    expect($run->statements[0]->lastInsertId)->toBe((string) $this->connection->scalar("SELECT MIN(id) FROM `{$this->database}`.`numbers` WHERE n >= 100"));
    expect($run->statements[1]->affectedRows)->toBe(2);
    expect($run->statements[2]->warnings[0]['code'])->toBe(1292);
});

test('destructive statements need confirmation and nothing runs before it', function () {
    expect(fn () => ($this->run)('SELECT 1; DELETE FROM numbers'))->toThrow(DestructiveStatementsException::class);
    expect(($this->run)('SELECT COUNT(*) FROM numbers')->statements[0]->rows)->toBe([[50]]);

    $run = ($this->run)('DELETE FROM numbers', ['confirmedDestructive' => true]);

    expect($run->statements[0]->affectedRows)->toBe(50);
});

test('read-only mode allows reading and refuses everything else', function () {
    config(['dbnova.read_only' => true]);

    expect(($this->run)('SELECT COUNT(*) FROM numbers; SHOW TABLES')->succeeded())->toBeTrue();
    expect(fn () => ($this->run)('SELECT 1; UPDATE numbers SET n = 1 WHERE id = 1'))->toThrow(ReadOnlyModeException::class);
});

test('a SELECT longer than the time limit is stopped by MySQL', function () {
    config(['dbnova.limits.query_timeout_ms' => 200]);
    app()->forgetScopedInstances();
    actingAsMySqlUser(User::query()->where('username', $this->credentials['username'])->sole(), $this->credentials['password']);

    $run = app(DatabaseAdapterInterface::class)->queries()->run(new QueryRequest(
        'SELECT COUNT(*) FROM information_schema.COLUMNS a, information_schema.COLUMNS b, information_schema.COLUMNS c',
    ));

    expect($run->statements[0]->status)->toBe('error');
    expect($run->statements[0]->errorCode)->toBe(3024);
    expect($run->statements[0]->error)->toBe('The query ran longer than 0.2 seconds and MySQL stopped it.');
});

test('a running query on another connection can be cancelled', function () {
    if (! extension_loaded('mysqli')) {
        $this->markTestSkipped('The mysqli extension is needed to run a query in the background.');
    }

    $socket = config('dbnova.connection.socket');
    $other = new mysqli(
        filled($socket) ? 'localhost' : config('dbnova.connection.host'),
        $this->credentials['username'],
        $this->credentials['password'],
        '',
        (int) config('dbnova.connection.port'),
        filled($socket) ? $socket : null,
    );
    $other->query('SELECT SLEEP(20)', MYSQLI_ASYNC);
    $startedAt = microtime(true);

    expect($this->queries->cancel($other->thread_id))->toBeTrue();

    $result = $other->reap_async_query();
    $elapsed = microtime(true) - $startedAt;
    $other->close();

    expect($elapsed)->toBeLessThan(5.0);
    expect($result === false || $result->fetch_row()[0] === '1')->toBeTrue();
});

test('cancelling a connection that is gone reports that nothing was cancelled', function () {
    expect($this->queries->cancel(2_000_000_000))->toBeFalse();
});

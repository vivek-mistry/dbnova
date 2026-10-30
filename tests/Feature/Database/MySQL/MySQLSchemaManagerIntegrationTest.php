<?php

use App\Database\Adapters\MySQL\MySQLConnection;
use App\Database\Contracts\DatabaseAdapterInterface;
use App\Database\Exceptions\DatabaseOperationException;
use App\Models\User;

/**
 * Runs against a real MySQL server. Set DBNOVA_TEST_DB_USERNAME and
 * DBNOVA_TEST_DB_PASSWORD (an account limited to `dbnova\_test\_%`
 * databases) in .env to enable it; otherwise every test is skipped.
 */
beforeEach(function () {
    $credentials = mySqlTestCredentials();

    if ($credentials === null) {
        $this->markTestSkipped('Set DBNOVA_TEST_DB_USERNAME in .env to run MySQL integration tests.');
    }

    actingAsMySqlUser(User::factory()->create(['username' => $credentials['username']]), $credentials['password']);

    $this->schema = app(DatabaseAdapterInterface::class)->schema();
    $this->connection = app(MySQLConnection::class);
    $this->databaseName = 'dbnova_test_'.bin2hex(random_bytes(4));
    $this->renamedName = $this->databaseName.'_renamed';
})->group('mysql');

afterEach(function () {
    if (isset($this->connection)) {
        $this->connection->statement("DROP DATABASE IF EXISTS `{$this->databaseName}`");
        $this->connection->statement("DROP DATABASE IF EXISTS `{$this->renamedName}`");
    }
});

test('a created database is listed with its collation and dropped again', function () {
    $this->schema->createDatabase($this->databaseName, 'utf8mb4_unicode_ci');

    expect($this->schema->database($this->databaseName))
        ->collation->toBe('utf8mb4_unicode_ci')
        ->tableCount->toBe(0)
        ->isSystem->toBeFalse();

    $this->schema->dropDatabase($this->databaseName);

    expect($this->schema->database($this->databaseName))->toBeNull();
});

test('renaming moves the tables and their rows to the new database', function () {
    $this->schema->createDatabase($this->databaseName);
    $this->connection->statement("CREATE TABLE `{$this->databaseName}`.`orders` (id INT PRIMARY KEY)");
    $this->connection->statement("INSERT INTO `{$this->databaseName}`.`orders` VALUES (1), (2), (3)");

    $this->schema->renameDatabase($this->databaseName, $this->renamedName);

    expect($this->schema->database($this->databaseName))->toBeNull();
    expect($this->schema->tables($this->renamedName))->toHaveCount(1);
    expect($this->connection->scalar("SELECT COUNT(*) FROM `{$this->renamedName}`.`orders`"))->toBe(3);
});

test('a database with a view cannot be renamed and is left untouched', function () {
    $this->schema->createDatabase($this->databaseName);
    $this->connection->statement("CREATE TABLE `{$this->databaseName}`.`orders` (id INT PRIMARY KEY)");
    $this->connection->statement("CREATE VIEW `{$this->databaseName}`.`order_ids` AS SELECT id FROM `{$this->databaseName}`.`orders`");

    expect(fn () => $this->schema->renameDatabase($this->databaseName, $this->renamedName))
        ->toThrow(DatabaseOperationException::class, 'contains 1 view');

    expect($this->schema->database($this->databaseName))->not->toBeNull();
    expect($this->schema->database($this->renamedName))->toBeNull();
});

test('server info reports the running MySQL server', function () {
    $info = app(DatabaseAdapterInterface::class)->server()->info();

    expect($info->version)->toMatch('/^\d+\.\d+\.\d+/');
    expect($info->uptimeSeconds)->toBeGreaterThan(0);
    expect($info->databaseCount)->toBeGreaterThan(0);
});

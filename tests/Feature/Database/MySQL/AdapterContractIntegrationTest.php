<?php

use App\Database\Adapters\MySQL\MySQLConnection;
use App\Database\Contracts\DatabaseAdapterInterface;
use App\Database\Contracts\ExporterInterface;
use App\Database\Contracts\ImporterInterface;
use App\Database\DTOs\CollationInfo;
use App\Database\DTOs\DatabaseInfo;
use App\Database\DTOs\TableRef;
use App\Models\User;

/**
 * The adapter contract methods that the feature-level integration tests do not
 * reach, each checked against a real MySQL server. With the other
 * MySQL*IntegrationTest files, every public contract method runs on MySQL.
 */
beforeEach(function () {
    $credentials = mySqlTestCredentials() ?? $this->markTestSkipped('Set DBNOVA_TEST_DB_USERNAME in .env to run MySQL integration tests.');
    actingAsMySqlUser(User::factory()->create(['username' => $credentials['username']]), $credentials['password']);

    $this->adapter = app(DatabaseAdapterInterface::class);
    $this->connection = app(MySQLConnection::class);
    $this->database = 'dbnova_test_contract_'.bin2hex(random_bytes(3));
    $this->connection->statement("CREATE DATABASE `{$this->database}`");
    $this->connection->statement("CREATE TABLE `{$this->database}`.`orders` (id INT PRIMARY KEY, note VARCHAR(20))");
    $this->connection->statement("CREATE VIEW `{$this->database}`.`order_notes` AS SELECT note FROM `{$this->database}`.`orders`");
})->group('mysql');

afterEach(function () {
    if (isset($this->connection, $this->database)) {
        $this->connection->statement("DROP DATABASE IF EXISTS `{$this->database}`");
    }
});

test('the adapter names its engine and hands out the bulk tools', function () {
    expect($this->adapter->engine())->toBe('mysql')
        ->and($this->adapter->importer())->toBeInstanceOf(ImporterInterface::class)
        ->and($this->adapter->exporter())->toBeInstanceOf(ExporterInterface::class);
});

test('the schema manager lists databases, columns per table and collations', function () {
    $mine = collect($this->adapter->schema()->databases())->first(fn (DatabaseInfo $info): bool => $info->name === $this->database);

    expect($mine)->not->toBeNull()
        ->and($mine->tableCount)->toBe(2)
        ->and($this->adapter->schema()->columnsByTable($this->database))->toBe(['order_notes' => ['note'], 'orders' => ['id', 'note']]);

    $collations = $this->adapter->schema()->collations();
    expect(collect($collations)->contains(fn (CollationInfo $c): bool => $c->name === 'utf8mb4_bin' && $c->characterSet === 'utf8mb4'))->toBeTrue();
});

test('rename blockers name the views, and busy sessions count other connections in the database', function () {
    expect($this->adapter->schema()->renameBlockers($this->database))->toBe(['1 view'])
        ->and($this->adapter->schema()->busySessionCount($this->database))->toBe(0);
});

test('the table browser returns the CREATE statement and the manager its column types and engines', function () {
    expect($this->adapter->browser()->createStatement(new TableRef($this->database, 'orders')))->toStartWith('CREATE TABLE `orders`')
        ->and($this->adapter->tables()->columnTypes())->toContain('varchar', 'int', 'json')
        ->and($this->adapter->tables()->engines())->toContain('InnoDB');
});

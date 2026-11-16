<?php

use App\Database\Adapters\MySQL\MySQLConnection;
use App\Database\Adapters\MySQL\MySQLConnector;
use App\Models\User;

/**
 * Runs against a real MySQL server when DBNOVA_TEST_DB_USERNAME is set.
 */
beforeEach(function () {
    $credentials = mySqlTestCredentials();

    if ($credentials === null) {
        $this->markTestSkipped('Set DBNOVA_TEST_DB_USERNAME in .env to run MySQL integration tests.');
    }

    $this->credentials = $credentials;
    actingAsMySqlUser(User::factory()->create(['username' => $credentials['username']]), $credentials['password']);
    $this->connection = app(MySQLConnection::class);
})->group('mysql');

test('the connection options reach PDO', function () {
    $pdo = $this->connection->pdo();

    expect($pdo->getAttribute(PDO::ATTR_ERRMODE))->toBe(PDO::ERRMODE_EXCEPTION);
    expect((bool) $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES))->toBeFalse();
    expect($pdo->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE))->toBe(PDO::FETCH_ASSOC);
});

test('writes are committed and visible to other connections', function () {
    $database = 'dbnova_test_'.bin2hex(random_bytes(4));
    $this->connection->statement("CREATE DATABASE `{$database}`");

    try {
        $this->connection->statement("CREATE TABLE `{$database}`.`items` (id INT PRIMARY KEY)");
        $this->connection->statement("INSERT INTO `{$database}`.`items` VALUES (1)");

        $other = app(MySQLConnector::class)->connect($this->credentials['username'], $this->credentials['password']);

        expect((int) $other->query("SELECT COUNT(*) FROM `{$database}`.`items`")->fetchColumn())->toBe(1);
    } finally {
        $this->connection->statement("DROP DATABASE IF EXISTS `{$database}`");
    }
});

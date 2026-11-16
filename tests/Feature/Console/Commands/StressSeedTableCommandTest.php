<?php

use App\Database\Adapters\MySQL\MySQLConnection;

test('it refuses to write outside dbnova_test_ databases', function () {
    config(['dbnova.testing.username' => 'dbnova_test']);
    $this->mock(MySQLConnection::class)->shouldNotReceive('statement');

    $this->artisan('dbnova:stress:seed-table', ['--database' => 'shop'])
        ->expectsOutputToContain('The target database must start with "dbnova_test_"')
        ->assertFailed();
});

test('it refuses to run without the test MySQL account', function () {
    config(['dbnova.testing.username' => null]);
    $this->mock(MySQLConnection::class)->shouldNotReceive('statement');

    $this->artisan('dbnova:stress:seed-table')
        ->expectsOutputToContain('Set DBNOVA_TEST_DB_USERNAME')
        ->assertFailed();
});

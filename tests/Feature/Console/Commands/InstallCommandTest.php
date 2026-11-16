<?php

use App\Services\System\SystemCheck;
use App\Services\System\SystemCheckService;

function fakeSystemChecks(SystemCheck ...$checks): void
{
    test()->partialMock(SystemCheckService::class, fn ($mock) => $mock
        ->shouldReceive('run')
        ->andReturn($checks));
}

test('it reports the system checks and tells the user to sign in with MySQL', function () {
    config(['dbnova.connection.host' => '127.0.0.1', 'dbnova.connection.port' => 3306, 'dbnova.connection.socket' => null]);
    fakeSystemChecks(SystemCheck::ok('PHP version', 'PHP 8.3.0'));

    $this->artisan('dbnova:install')
        ->expectsTable(['Check', 'Status', 'Detail'], [['PHP version', 'ok', 'PHP 8.3.0']])
        ->expectsOutputToContain('Sign in with a MySQL account on 127.0.0.1:3306.')
        ->assertSuccessful();
});

test('it fails when a system check fails', function () {
    fakeSystemChecks(SystemCheck::failed('mysql client', 'Not found or not executable: /missing/mysql'));

    $this->artisan('dbnova:install')
        ->expectsOutputToContain('Some system checks failed.')
        ->assertFailed();
});

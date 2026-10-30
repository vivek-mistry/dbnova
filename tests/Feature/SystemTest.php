<?php

use App\Services\System\SystemCheck;
use App\Services\System\SystemCheckService;
use Inertia\Testing\AssertableInertia as Assert;

test('the system page lists every system check with its hint', function () {
    $this->partialMock(SystemCheckService::class, fn ($mock) => $mock
        ->shouldReceive('run')
        ->once()
        ->andReturn([
            SystemCheck::ok('PHP version', 'PHP 8.3.0'),
            SystemCheck::failed('mysql client', 'Not found or not executable: /missing/mysql', 'Set DBNOVA_MYSQL_BIN.'),
        ]));
    actingAsMySqlUser();

    $response = $this->get(route('system.index'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('system/Health')
        ->has('checks', 2)
        ->where('checks.0', [
            'name' => 'PHP version',
            'status' => 'ok',
            'detail' => 'PHP 8.3.0',
            'hint' => null,
        ])
        ->where('checks.1', [
            'name' => 'mysql client',
            'status' => 'failed',
            'detail' => 'Not found or not executable: /missing/mysql',
            'hint' => 'Set DBNOVA_MYSQL_BIN.',
        ])
    );
});

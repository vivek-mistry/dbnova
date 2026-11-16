<?php

use App\Database\Contracts\DatabaseAdapterInterface;
use App\Database\DTOs\ServerInfo;
use App\Database\Exceptions\DatabaseOperationException;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('server statistics load after the dashboard renders', function () {
    $this->mock(DatabaseAdapterInterface::class)
        ->shouldReceive('server->info')
        ->andReturn(new ServerInfo('8.0.39', 90061, 12, 1073741824));
    actingAsMySqlUser();

    $response = $this->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->missing('server')
        ->loadDeferredProps(fn (Assert $reload) => $reload->where('server', [
            'info' => [
                'version' => '8.0.39',
                'uptimeSeconds' => 90061,
                'databaseCount' => 12,
                'totalSizeBytes' => 1073741824,
            ],
            'error' => null,
        ]))
    );
});

test('a MySQL failure is shown on the dashboard instead of the statistics', function () {
    $this->mock(DatabaseAdapterInterface::class)
        ->shouldReceive('server->info')
        ->andThrow(new DatabaseOperationException('Your MySQL account does not have permission to do that.'));
    actingAsMySqlUser();

    $response = $this->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->loadDeferredProps(fn (Assert $reload) => $reload->where('server', [
            'info' => null,
            'error' => 'Your MySQL account does not have permission to do that.',
        ]))
    );
});

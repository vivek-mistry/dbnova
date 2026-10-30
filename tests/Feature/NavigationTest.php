<?php

use Inertia\Testing\AssertableInertia as Assert;

dataset('placeholder sections', [
    'sql editor' => ['sql.index', 'SQL Editor', 5],
    'import / export' => ['import-export.index', 'Import / Export', 6],
    'processes' => ['processes.index', 'Processes', 8],
]);

test('guests are redirected to the login page from every section', function (string $routeName) {
    $response = $this->get(route($routeName));

    $response->assertRedirect(route('login'));
})->with([
    'dashboard' => ['dashboard'],
    'databases' => ['databases.index'],
    'sql editor' => ['sql.index'],
    'import / export' => ['import-export.index'],
    'processes' => ['processes.index'],
    'system' => ['system.index'],
]);

test('unbuilt sections render a coming soon page naming their phase', function (string $routeName, string $title, int $phase) {
    actingAsMySqlUser();

    $response = $this->get(route($routeName));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('ComingSoon')
        ->where('title', $title)
        ->where('phase', $phase)
    );
})->with('placeholder sections');

test('pages share whether read-only mode is enabled', function (bool $readOnly) {
    config(['dbnova.read_only' => $readOnly]);
    actingAsMySqlUser();

    $response = $this->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page->where('dbnova.readOnly', $readOnly));
})->with([
    'enabled' => [true],
    'disabled' => [false],
]);

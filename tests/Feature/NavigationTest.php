<?php

use Inertia\Testing\AssertableInertia as Assert;

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

test('every section in the navigation renders a built page', function (string $routeName, string $component) {
    fakeDatabaseAdapter();
    actingAsMySqlUser();

    $this->get(route($routeName))->assertInertia(fn (Assert $page) => $page->component($component));
})->with([
    'processes' => ['processes.index', 'processes/Index'],
]);

test('pages share whether read-only mode is enabled', function (bool $readOnly) {
    config(['dbnova.read_only' => $readOnly]);
    actingAsMySqlUser();

    $response = $this->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page->where('dbnova.readOnly', $readOnly));
})->with([
    'enabled' => [true],
    'disabled' => [false],
]);

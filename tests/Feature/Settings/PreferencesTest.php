<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config([
        'dbnova.limits.page_size' => 100,
        'dbnova.limits.query_row_cap' => 1000,
        'dbnova.limits.query_timeout_ms' => 30000,
        'dbnova.limits.max.page_size' => 500,
        'dbnova.limits.max.query_row_cap' => 5000,
        'dbnova.limits.max.query_timeout_ms' => 120000,
    ]);
});

test('the page shows the defaults and the allowed range', function () {
    actingAsMySqlUser();

    $this->get(route('preferences.edit'))->assertInertia(fn (Assert $page) => $page
        ->component('settings/Preferences')
        ->where('fields.page_size', ['min' => 10, 'max' => 500, 'default' => 100, 'value' => 100, 'isDefault' => true])
        ->where('fields.query_timeout_ms.max', 120000));
});

test('settings redirects to preferences', function () {
    actingAsMySqlUser();

    $this->get('/settings')->assertRedirect('/settings/preferences');
});

test('preferences are saved per user and empty values mean the default', function () {
    $user = actingAsMySqlUser();

    $this->patch(route('preferences.update'), ['page_size' => '250', 'query_row_cap' => '', 'query_timeout_ms' => 60000])
        ->assertRedirect(route('preferences.edit'));

    expect($user->fresh()->preferences)->toBe(['page_size' => 250, 'query_timeout_ms' => 60000]);

    $this->patch(route('preferences.update'), ['page_size' => null, 'query_row_cap' => null, 'query_timeout_ms' => null]);
    expect($user->fresh()->preferences)->toBeNull();
});

test('values outside the range set by .env are refused', function (array $payload, string $field, string $message) {
    actingAsMySqlUser();

    $this->patch(route('preferences.update'), $payload)->assertSessionHasErrors([$field => $message]);
})->with([
    'page size above the maximum' => [['page_size' => 501], 'page_size', 'The rows per page field must not be greater than 500.'],
    'row cap below the minimum' => [['query_row_cap' => 5], 'query_row_cap', 'The row limit field must be at least 10.'],
    'timeout above the maximum, in seconds' => [['query_timeout_ms' => 600000], 'query_timeout_ms', 'The query timeout may not be more than 120 seconds.'],
    'not a number' => [['page_size' => 'lots'], 'page_size', 'The rows per page field must be an integer.'],
]);

test('saved preferences change the table page size and the SQL editor limits', function () {
    ['schema' => $schema, 'queries' => $queries] = fakeDatabaseAdapter();
    $schema->shouldReceive('databases')->andReturn([]);
    actingAsMySqlUser(User::factory()->create(['preferences' => ['query_row_cap' => 250, 'query_timeout_ms' => 5000]]));

    $this->get(route('sql.index'))->assertInertia(fn (Assert $page) => $page
        ->where('rowCap', 250)
        ->where('timeoutSeconds', 5));
});

test('a saved value above a lowered .env maximum is capped', function () {
    ['schema' => $schema] = fakeDatabaseAdapter();
    $schema->shouldReceive('databases')->andReturn([]);
    actingAsMySqlUser(User::factory()->create(['preferences' => ['query_row_cap' => 4000]]));
    config(['dbnova.limits.max.query_row_cap' => 2000]);

    $this->get(route('sql.index'))->assertInertia(fn (Assert $page) => $page->where('rowCap', 2000));
});

test('another user keeps the defaults', function () {
    ['schema' => $schema] = fakeDatabaseAdapter();
    $schema->shouldReceive('databases')->andReturn([]);
    User::factory()->create(['preferences' => ['query_row_cap' => 250]]);
    actingAsMySqlUser();

    $this->get(route('sql.index'))->assertInertia(fn (Assert $page) => $page->where('rowCap', 1000));
});

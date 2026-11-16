<?php

use App\Database\DTOs\Maintenance\MaintenanceOperation;
use App\Database\DTOs\Maintenance\MaintenanceResult;
use App\Database\Exceptions\DatabaseOperationException;
use App\Database\Exceptions\ReadOnlyModeException;
use App\Models\AuditLog;

beforeEach(function () {
    ['tables' => $this->tables] = fakeDatabaseAdapter();
    actingAsMySqlUser();
});

test('maintenance returns MySQL\'s result rows and audits the change', function () {
    $this->tables->shouldReceive('maintain')->once()
        ->with('shop', ['orders', 'customers'], MaintenanceOperation::Optimize)
        ->andReturn([
            new MaintenanceResult('shop.orders', 'optimize', 'note', 'Table does not support optimize, doing recreate + analyze instead'),
            new MaintenanceResult('shop.orders', 'optimize', 'status', 'OK'),
            new MaintenanceResult('shop.customers', 'optimize', 'error', 'Table is marked as crashed'),
        ]);

    $this->postJson(route('maintenance.run', 'shop'), ['operation' => 'optimize', 'tables' => ['orders', 'customers']])
        ->assertOk()
        ->assertJsonPath('operation', 'optimize')
        ->assertJsonCount(3, 'results')
        ->assertJsonPath('results.1', ['table' => 'shop.orders', 'operation' => 'optimize', 'type' => 'status', 'message' => 'OK']);

    $audit = AuditLog::query()->sole();
    expect($audit->action)->toBe('table.optimize')
        ->and($audit->database_name)->toBe('shop')
        ->and($audit->table_name)->toBeNull()
        ->and($audit->details)->toBe(['tables' => ['orders', 'customers'], 'errors' => 1])
        ->and($audit->succeeded)->toBeTrue();
});

test('a single table is named on its audit entry', function () {
    $this->tables->shouldReceive('maintain')->once()->andReturn([new MaintenanceResult('shop.orders', 'analyze', 'status', 'OK')]);

    $this->postJson(route('maintenance.run', 'shop'), ['operation' => 'analyze', 'tables' => ['orders']])->assertOk();

    expect(AuditLog::query()->sole())->action->toBe('table.analyze')->table_name->toBe('orders');
});

test('CHECK TABLE only reads, so it is not audited', function () {
    $this->tables->shouldReceive('maintain')->once()->andReturn([new MaintenanceResult('shop.orders', 'check', 'status', 'OK')]);

    $this->postJson(route('maintenance.run', 'shop'), ['operation' => 'check', 'tables' => ['orders']])->assertOk();

    expect(AuditLog::query()->count())->toBe(0);
});

test('the request is validated', function (array $payload, string $field) {
    $this->tables->shouldNotReceive('maintain');

    $this->postJson(route('maintenance.run', 'shop'), $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'unknown operation' => [['operation' => 'drop', 'tables' => ['orders']], 'operation'],
    'no tables' => [['operation' => 'check', 'tables' => []], 'tables'],
    'a repeated table' => [['operation' => 'check', 'tables' => ['orders', 'orders']], 'tables.0'],
    'too many tables' => [['operation' => 'check', 'tables' => array_map(fn (int $i): string => "t{$i}", range(1, 201))], 'tables'],
]);

test('read-only mode refuses changes with 403', function () {
    $this->tables->shouldReceive('maintain')->andThrow(new ReadOnlyModeException);

    $this->postJson(route('maintenance.run', 'shop'), ['operation' => 'optimize', 'tables' => ['orders']])->assertForbidden();
    expect(AuditLog::query()->count())->toBe(0);
});

test('a refused statement is reported and audited as failed', function () {
    $this->tables->shouldReceive('maintain')->andThrow(new DatabaseOperationException('The table "nope" does not exist in "shop".'));

    $this->postJson(route('maintenance.run', 'shop'), ['operation' => 'repair', 'tables' => ['nope']])
        ->assertUnprocessable()
        ->assertJson(['message' => 'The table "nope" does not exist in "shop".']);

    expect(AuditLog::query()->sole())->action->toBe('table.repair')->succeeded->toBeFalse();
});

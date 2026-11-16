<?php

use App\Database\Contracts\DatabaseAdapterInterface;
use App\Database\Contracts\ServerInspectorInterface;
use App\Database\DTOs\ProcessInfo;
use App\Database\DTOs\ServerMetrics;
use App\Database\Exceptions\DatabaseOperationException;
use App\Database\Exceptions\ReadOnlyModeException;
use App\Models\AuditLog;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;

beforeEach(function () {
    actingAsMySqlUser();

    $this->server = Mockery::mock(ServerInspectorInterface::class);
    $this->mock(DatabaseAdapterInterface::class, fn (MockInterface $adapter) => $adapter->shouldReceive('server')->andReturn($this->server));

    $this->process = fn (int $id, string $command = 'Query', ?string $info = 'SELECT SLEEP(60)', bool $current = false) => new ProcessInfo(
        $id, 'app', 'localhost:51234', 'shop', $command, 42, 'User sleep', $info, $current,
    );
});

test('the processes page loads the list after rendering', function () {
    $this->server->shouldReceive('processes')->andReturn([($this->process)(7)]);

    $this->get(route('processes.index'))->assertInertia(fn (Assert $page) => $page
        ->component('processes/Index')
        ->missing('processes')
        ->loadDeferredProps(fn (Assert $reload) => $reload
            ->where('processes.error', null)
            ->where('processes.processes.0.id', 7)
            ->where('processes.processes.0.info', 'SELECT SLEEP(60)'))
    );
});

test('the list endpoint is polled as JSON and reports MySQL errors instead of failing', function () {
    $this->server->shouldReceive('processes')->once()->andReturn([($this->process)(7)]);
    $this->getJson(route('processes.list'))->assertOk()->assertJsonPath('processes.0.user', 'app');

    $this->server->shouldReceive('processes')->andThrow(new DatabaseOperationException('Access denied; you need the PROCESS privilege'));
    $this->getJson(route('processes.list'))->assertOk()->assertJson(['processes' => [], 'error' => 'Access denied; you need the PROCESS privilege']);
});

test('stopping a query needs an explicit confirmation', function (array $payload, string $field) {
    $this->server->shouldNotReceive('kill');

    $this->postJson(route('processes.kill', 7), $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'no confirmation' => [['mode' => 'query'], 'confirm'],
    'unknown mode' => [['mode' => 'everything', 'confirm' => true], 'mode'],
]);

test('stopping a query is audited with whose work was stopped', function () {
    $this->server->shouldReceive('processes')->andReturn([($this->process)(7)]);
    $this->server->shouldReceive('kill')->once()->with(7, false)->andReturnTrue();

    $this->postJson(route('processes.kill', 7), ['mode' => 'query', 'confirm' => true])
        ->assertOk()->assertJson(['killed' => true, 'message' => 'The query on connection 7 was stopped.']);

    $audit = AuditLog::query()->sole();
    expect($audit->action)->toBe('process.kill-query')
        ->and($audit->database_name)->toBe('shop')
        ->and($audit->details)->toMatchArray(['id' => 7, 'user' => 'app', 'command' => 'Query'])
        ->and($audit->succeeded)->toBeTrue();
});

test('killing a connection uses KILL and is audited separately', function () {
    $this->server->shouldReceive('processes')->andReturn([($this->process)(9, 'Sleep', null)]);
    $this->server->shouldReceive('kill')->once()->with(9, true)->andReturnTrue();

    $this->postJson(route('processes.kill', 9), ['mode' => 'connection', 'confirm' => true])
        ->assertOk()->assertJson(['message' => 'Connection 9 was closed.']);

    expect(AuditLog::query()->sole()->action)->toBe('process.kill');
});

test('a process that already ended is reported, not killed', function () {
    $this->server->shouldReceive('processes')->andReturn([]);
    $this->server->shouldNotReceive('kill');

    $this->postJson(route('processes.kill', 7), ['mode' => 'query', 'confirm' => true])->assertNotFound();
});

test('read-only mode refusals and MySQL refusals are reported and audited as failures', function (Throwable $exception, int $status) {
    $this->server->shouldReceive('processes')->andReturn([($this->process)(7)]);
    $this->server->shouldReceive('kill')->andThrow($exception);

    $this->postJson(route('processes.kill', 7), ['mode' => 'query', 'confirm' => true])
        ->assertStatus($status)->assertJsonPath('message', $exception->getMessage());

    expect(AuditLog::query()->sole()->succeeded)->toBeFalse();
})->with([
    'read-only' => [new ReadOnlyModeException, 403],
    'not the owner' => [new DatabaseOperationException('You are not owner of thread 7'), 422],
]);

test('the variables and status pages load their rows after rendering', function (string $route, string $method, string $kind) {
    $this->server->shouldReceive($method)->andReturn([['name' => 'max_connections', 'value' => '151']]);

    $this->get(route($route))->assertInertia(fn (Assert $page) => $page
        ->component('processes/Variables')
        ->where('kind', $kind)
        ->loadDeferredProps(fn (Assert $reload) => $reload->where('rows.items.0.value', '151'))
    );
})->with([
    'variables' => ['server.variables', 'variables', 'variables'],
    'status' => ['server.status', 'status', 'status'],
]);

test('the dashboard polls the server counters as JSON', function () {
    $this->server->shouldReceive('metrics')->andReturn(new ServerMetrics(1.5, 100, 2000, 5, 1, 151, 8192, 1024, 10, 20));

    $this->getJson(route('server.metrics'))->assertOk()->assertJson([
        'questions' => 2000, 'threadsConnected' => 5, 'maxConnections' => 151, 'bufferPoolPagesFree' => 1024,
    ]);
});

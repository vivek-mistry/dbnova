<?php

use App\Database\Contracts\DatabaseAdapterInterface;
use App\Database\Contracts\ServerInspectorInterface;
use App\Database\Exceptions\DatabaseOperationException;
use App\Models\AuditLog;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;

beforeEach(function () {
    $this->me = actingAsMySqlUser(User::factory()->create(['username' => 'alice']));
    $this->other = User::factory()->create(['username' => 'bob']);

    $this->server = Mockery::mock(ServerInspectorInterface::class);
    $this->mock(DatabaseAdapterInterface::class, fn (MockInterface $adapter) => $adapter->shouldReceive('server')->andReturn($this->server));

    $entry = fn (User $user, string $action, string $database, bool $succeeded = true) => AuditLog::query()->create([
        'user_id' => $user->id,
        'mysql_username' => $user->username,
        'action' => $action,
        'database_name' => $database,
        'succeeded' => $succeeded,
        'error' => $succeeded ? null : 'MySQL error: boom',
    ]);

    $entry($this->me, 'database.create', 'shop');
    $entry($this->me, 'table.optimize', 'shop', false);
    $entry($this->other, 'database.drop', 'payroll');
});

test('an account sees only its own entries, newest first', function () {
    $this->server->shouldReceive('hasGlobalPrivilege')->with('CREATE USER')->andReturnFalse();

    $this->get(route('audit.index'))->assertInertia(fn (Assert $page) => $page
        ->component('system/Audit')
        ->where('seesAll', false)
        ->where('entries.total', 2)
        ->where('entries.data.0.action', 'table.optimize')
        ->where('entries.data.0.error', 'MySQL error: boom')
        ->where('entries.data.1.action', 'database.create')
        ->where('actions', ['database.create', 'table.optimize'])
        ->where('users', []));
});

test('a user filter cannot reveal another account\'s entries', function () {
    $this->server->shouldReceive('hasGlobalPrivilege')->andReturnFalse();

    $this->get(route('audit.index', ['user' => 'bob']))->assertInertia(fn (Assert $page) => $page
        ->where('entries.total', 2)
        ->where('filters.user', null));
});

test('an account that can create MySQL users sees everyone\'s entries and can filter by user', function () {
    $this->server->shouldReceive('hasGlobalPrivilege')->with('CREATE USER')->andReturnTrue();

    $this->get(route('audit.index'))->assertInertia(fn (Assert $page) => $page
        ->where('seesAll', true)
        ->where('entries.total', 3)
        ->where('users', ['alice', 'bob']));

    $this->get(route('audit.index', ['user' => 'bob']))->assertInertia(fn (Assert $page) => $page
        ->where('entries.total', 1)
        ->where('entries.data.0.database', 'payroll'));
});

test('entries filter by action, database and result', function () {
    $this->server->shouldReceive('hasGlobalPrivilege')->andReturnTrue();

    $this->get(route('audit.index', ['action' => 'database.drop']))->assertInertia(fn (Assert $page) => $page->where('entries.total', 1));
    $this->get(route('audit.index', ['database' => 'shop']))->assertInertia(fn (Assert $page) => $page->where('entries.total', 2));
    $this->get(route('audit.index', ['result' => 'failed']))->assertInertia(fn (Assert $page) => $page
        ->where('entries.total', 1)
        ->where('entries.data.0.action', 'table.optimize'));
    $this->get(route('audit.index', ['result' => 'maybe']))->assertSessionHasErrors('result');
});

test('when the privilege check fails, the account sees only its own entries', function () {
    $this->server->shouldReceive('hasGlobalPrivilege')->andThrow(new DatabaseOperationException('MySQL error: gone'));

    $this->get(route('audit.index'))->assertInertia(fn (Assert $page) => $page
        ->where('seesAll', false)
        ->where('entries.total', 2));
});

test('entries are paginated', function () {
    $this->server->shouldReceive('hasGlobalPrivilege')->andReturnFalse();
    AuditLog::factory()->count(60)->create(['user_id' => $this->me->id, 'mysql_username' => 'alice']);

    $this->get(route('audit.index', ['page' => 2]))->assertInertia(fn (Assert $page) => $page
        ->where('entries.current_page', 2)
        ->where('entries.total', 62)
        ->has('entries.data', 12));
});

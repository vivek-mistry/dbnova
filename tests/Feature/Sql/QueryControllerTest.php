<?php

use App\Database\DTOs\DatabaseInfo;
use App\Database\DTOs\Query\QueryRequest;
use App\Database\DTOs\Query\QueryRun;
use App\Database\DTOs\Query\StatementResult;
use App\Database\Exceptions\DestructiveStatementsException;
use App\Database\Exceptions\ReadOnlyModeException;
use App\Models\AuditLog;
use App\Models\QueryHistory;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    ['schema' => $this->schema, 'queries' => $this->queries] = fakeDatabaseAdapter();
    $this->user = actingAsMySqlUser();
});

function runPayload(array $overrides = []): array
{
    return ['sql' => 'SELECT 1', 'database' => 'shop', 'runId' => (string) Str::uuid(), ...$overrides];
}

function okResult(string $keyword = 'SELECT', ?array $rows = [[1]], ?int $affected = null): StatementResult
{
    return new StatementResult(1, $keyword.' …', $keyword, 'ok', $rows === null ? null : ['1'], $rows, false, $affected, durationMs: 1.0);
}

describe('page', function () {
    test('opens preset to a database and a table, with this user\'s history and saved queries', function () {
        $this->schema->shouldReceive('databases')->andReturn([new DatabaseInfo('shop', 'utf8mb4', 'x', 1, 1, false)]);
        QueryHistory::factory()->for($this->user)->create(['sql' => 'SELECT mine']);
        QueryHistory::factory()->for($this->user)->saved('Mine')->create();
        QueryHistory::factory()->create(['sql' => 'SELECT someone else']);

        $response = $this->get(route('sql.index', ['database' => 'shop', 'table' => 'or`ders']));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('sql/Index')
            ->where('database', 'shop')
            ->where('initialSql', 'SELECT * FROM `or``ders` LIMIT 100;')
            ->where('rowCap', 1000)
            ->has('history', 2)
            ->where('history.1.sql', 'SELECT mine')
            ->has('saved', 1)
            ->where('saved.0.name', 'Mine')
            ->missing('databases')
            ->loadDeferredProps(fn (Assert $reload) => $reload->where('databases', ['shop']))
        );
    });
});

describe('run', function () {
    test('runs the SQL in the chosen database and records it in history', function () {
        $this->queries->shouldReceive('run')
            ->once()
            ->withArgs(fn (QueryRequest $request) => $request->sql === 'SELECT 1' && $request->database === 'shop' && ! $request->confirmedDestructive && $request->rowCap === 1000)
            ->andReturn(new QueryRun([okResult()]));

        $response = $this->postJson(route('sql.run'), runPayload());

        $response->assertOk()
            ->assertJsonPath('succeeded', true)
            ->assertJsonPath('statements.0.rows', [[1]])
            ->assertJsonPath('history.sql', 'SELECT 1');
        expect(QueryHistory::query()->sole())
            ->user_id->toBe($this->user->id)
            ->status->toBe('ok')
            ->database_name->toBe('shop');
        expect(AuditLog::query()->count())->toBe(0);
    });

    test('SQL is kept exactly as typed', function () {
        $sql = "  SELECT ''  \n";
        $this->queries->shouldReceive('run')
            ->withArgs(fn (QueryRequest $request) => $request->sql === $sql)
            ->andReturn(new QueryRun([okResult()]));

        $this->postJson(route('sql.run'), runPayload(['sql' => $sql]))->assertOk();
    });

    test('a failed statement is reported, recorded and audited when it changes data', function () {
        $this->queries->shouldReceive('run')->andReturn(new QueryRun([
            okResult('UPDATE', null, 3),
            new StatementResult(2, 'DELETE …', 'DELETE', 'error', error: "Table 'shop.nope' doesn't exist", errorCode: 1146),
            new StatementResult(3, 'SELECT 1', 'SELECT', 'skipped'),
        ]));

        $response = $this->postJson(route('sql.run'), runPayload(['sql' => 'UPDATE …; DELETE …; SELECT 1']));

        $response->assertOk()
            ->assertJsonPath('succeeded', false)
            ->assertJsonPath('statements.1.errorCode', 1146)
            ->assertJsonPath('statements.2.status', 'skipped');
        expect(QueryHistory::query()->sole())
            ->status->toBe('error')
            ->error->toBe("Table 'shop.nope' doesn't exist");
        expect(AuditLog::query()->sole())
            ->action->toBe('sql.execute')
            ->succeeded->toBeFalse()
            ->details->toBe(['statements' => 3, 'keywords' => ['UPDATE', 'DELETE']]);
    });

    test('destructive SQL asks for confirmation with 409 and runs nothing', function () {
        $this->queries->shouldReceive('run')->andThrow(new DestructiveStatementsException([
            ['line' => 1, 'keyword' => 'DROP', 'sql' => 'DROP TABLE t'],
        ]));

        $response = $this->postJson(route('sql.run'), runPayload(['sql' => 'DROP TABLE t']));

        $response->assertConflict()->assertJsonPath('destructive.0.keyword', 'DROP');
        expect(QueryHistory::query()->count())->toBe(0);
    });

    test('confirming destructive SQL runs it', function () {
        $this->queries->shouldReceive('run')
            ->withArgs(fn (QueryRequest $request) => $request->confirmedDestructive)
            ->andReturn(new QueryRun([okResult('DROP', null, 0)]));

        $this->postJson(route('sql.run'), runPayload(['sql' => 'DROP TABLE t', 'confirmDestructive' => true]))->assertOk();
    });

    test('read-only mode is reported as 422', function () {
        $this->queries->shouldReceive('run')->andThrow(new ReadOnlyModeException);

        $this->postJson(route('sql.run'), runPayload(['sql' => 'DELETE FROM t WHERE id = 1']))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'DBNova is in read-only mode. Changes to the MySQL server are disabled.');
    });

    test('invalid requests are rejected', function (array $overrides, string $field) {
        $this->queries->shouldNotReceive('run');

        $this->postJson(route('sql.run'), runPayload($overrides))->assertUnprocessable()->assertJsonValidationErrors($field);
    })->with([
        'no sql' => [['sql' => ''], 'sql'],
        'run id not a uuid' => [['runId' => 'abc'], 'runId'],
        'sql over 1 MB' => [['sql' => str_repeat('a', 1_048_577)], 'sql'],
    ]);

    test('history keeps the newest 200 unsaved runs per user and every saved one', function () {
        $savedLongAgo = QueryHistory::factory()->for($this->user)->saved()->create();
        QueryHistory::factory()->for($this->user)->count(200)->create();
        $this->queries->shouldReceive('run')->andReturn(new QueryRun([okResult()]));

        $this->postJson(route('sql.run'), runPayload())->assertOk();

        expect(QueryHistory::query()->whereNull('saved_at')->count())->toBe(200);
        expect($savedLongAgo->fresh())->not->toBeNull();
    });
});

describe('cancel', function () {
    test('cancels the user\'s own running query by its run id', function () {
        $runId = (string) Str::uuid();
        Cache::put('dbnova:sql-run:'.$runId, ['connectionId' => 77, 'userId' => $this->user->id]);
        $this->queries->shouldReceive('cancel')->once()->with(77)->andReturn(true);

        $this->postJson(route('sql.cancel'), ['runId' => $runId])->assertExactJson(['cancelled' => true]);
    });

    test('runs started by another user cannot be cancelled', function () {
        $runId = (string) Str::uuid();
        Cache::put('dbnova:sql-run:'.$runId, ['connectionId' => 77, 'userId' => User::factory()->create()->id]);
        $this->queries->shouldNotReceive('cancel');

        $this->postJson(route('sql.cancel'), ['runId' => $runId])->assertExactJson(['cancelled' => false]);
    });

    test('the run id is registered while the query runs and removed afterwards', function () {
        $runId = (string) Str::uuid();
        $this->queries->shouldReceive('run')->andReturnUsing(function (QueryRequest $request, callable $onStart) use ($runId) {
            $onStart(42);
            expect(Cache::get('dbnova:sql-run:'.$runId))->toBe(['connectionId' => 42, 'userId' => $this->user->id]);

            return new QueryRun([okResult()]);
        });

        $this->postJson(route('sql.run'), runPayload(['runId' => $runId]))->assertOk();

        expect(Cache::has('dbnova:sql-run:'.$runId))->toBeFalse();
    });
});

test('the schema endpoint lists tables and columns for autocomplete', function () {
    $this->schema->shouldReceive('columnsByTable')->with('shop')->andReturn(['orders' => ['id', 'status']]);

    $this->getJson(route('sql.schema', ['database' => 'shop']))->assertExactJson(['tables' => ['orders' => ['id', 'status']]]);
});

describe('saved queries', function () {
    test('a history entry is saved under a name and unsaved with an empty one', function () {
        $entry = QueryHistory::factory()->for($this->user)->create();

        $this->patchJson(route('sql.history.save', $entry), ['name' => 'Paid orders'])
            ->assertOk()->assertJsonPath('saved', true)->assertJsonPath('name', 'Paid orders');
        $this->patchJson(route('sql.history.save', $entry), ['name' => ''])
            ->assertOk()->assertJsonPath('saved', false);
    });

    test('another user\'s history entry returns 404', function () {
        $entry = QueryHistory::factory()->create();

        $this->patchJson(route('sql.history.save', $entry), ['name' => 'Mine now'])->assertNotFound();
    });
});

test('guests cannot use the SQL editor', function () {
    auth()->logout();

    $this->postJson(route('sql.run'), runPayload())->assertUnauthorized();
    $this->get(route('sql.index'))->assertRedirect(route('login'));
});

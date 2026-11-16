<?php

use App\Database\Adapters\MySQL\MySQLConnector;
use App\Database\DTOs\Schema\AddColumn;
use App\Database\DTOs\Schema\ColumnDefaultKind;
use App\Database\DTOs\Schema\CreateTable;
use App\Database\DTOs\Schema\DdlPlan;
use App\Database\DTOs\Schema\DdlResult;
use App\Database\DTOs\Schema\DropTable;
use App\Database\DTOs\Schema\RenameTable;
use App\Database\DTOs\TableRef;
use App\Database\Exceptions\DatabaseOperationException;
use App\Database\Exceptions\LockingDdlRequiresConfirmationException;
use App\Database\Exceptions\ReadOnlyModeException;
use App\Models\AuditLog;
use App\Models\User;

beforeEach(function () {
    ['tables' => $this->tables] = fakeDatabaseAdapter();
    $this->user = actingAsMySqlUser(User::factory()->create(['username' => 'root']), 'Secret@123');
});

function addColumnPayload(array $column = []): array
{
    return [
        'table' => 'orders',
        'change' => [
            'type' => 'add_column',
            'column' => [
                'name' => 'paid',
                'type' => 'tinyint',
                'nullable' => false,
                'default' => ['kind' => 'value', 'value' => '0'],
                ...$column,
            ],
            'after' => 'status',
        ],
    ];
}

describe('preview', function () {
    test('returns the SQL and warnings without running anything', function () {
        $this->tables->shouldReceive('preview')
            ->once()
            ->withArgs(fn (TableRef $table, AddColumn $change) => $table == new TableRef('shop', 'orders')
                && $change->column->name === 'paid'
                && $change->after === 'status')
            ->andReturn(new DdlPlan(['ALTER TABLE `shop`.`orders` ADD COLUMN `paid` tinyint NOT NULL'], ['Big table.'], 2_000_000));
        $this->tables->shouldNotReceive('apply');

        $response = $this->postJson(route('schema.preview', 'shop'), addColumnPayload());

        $response->assertOk()->assertExactJson([
            'statements' => ['ALTER TABLE `shop`.`orders` ADD COLUMN `paid` tinyint NOT NULL'],
            'warnings' => ['Big table.'],
            'approximateRows' => 2_000_000,
        ]);
        expect(AuditLog::query()->count())->toBe(0);
    });

    test('an empty-string default reaches the builder unchanged', function () {
        $this->tables->shouldReceive('preview')
            ->once()
            ->withArgs(fn (TableRef $table, AddColumn $change) => $change->column->default->kind === ColumnDefaultKind::Value && $change->column->default->value === '')
            ->andReturn(new DdlPlan(['…']));

        $this->postJson(route('schema.preview', 'shop'), addColumnPayload(['type' => 'varchar', 'length' => '10', 'default' => ['kind' => 'value', 'value' => '']]))
            ->assertOk();
    });

    test('changes the builder rejects come back as 422 with the reason', function () {
        $this->tables->shouldReceive('preview')->andThrow(new DatabaseOperationException('VARCHAR columns need a length.'));

        $response = $this->postJson(route('schema.preview', 'shop'), addColumnPayload(['type' => 'varchar']));

        $response->assertUnprocessable()->assertExactJson(['message' => 'VARCHAR columns need a length.']);
    });

    test('unknown change types and bad names are rejected', function (array $payload, string $field) {
        $this->tables->shouldNotReceive('preview');

        $this->postJson(route('schema.preview', 'shop'), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    })->with([
        'unknown type' => [['table' => 'orders', 'change' => ['type' => 'drop_database']], 'change.type'],
        'table with a dot' => [['table' => 'a.b', 'change' => ['type' => 'truncate_table']], 'table'],
        'column with a slash' => [addColumnPayload(['name' => 'a/b']), 'change.column.name'],
        'bad default kind' => [addColumnPayload(['default' => ['kind' => 'expression']]), 'change.column.default.kind'],
    ]);

    test('previewing a destructive change needs no password', function () {
        $this->tables->shouldReceive('preview')->andReturn(new DdlPlan(['DROP TABLE `shop`.`orders`']));

        $this->postJson(route('schema.preview', 'shop'), ['table' => 'orders', 'change' => ['type' => 'drop_table']])
            ->assertOk();
    });
});

describe('apply', function () {
    test('applies the change, audits it and reports how MySQL ran it', function () {
        $this->tables->shouldReceive('apply')
            ->once()
            ->withArgs(fn (TableRef $table, AddColumn $change, bool $allowLocking) => $allowLocking === false)
            ->andReturn(new DdlResult(['ALTER …'], 'INSTANT'));

        $response = $this->postJson(route('schema.apply', 'shop'), addColumnPayload());

        $response->assertOk()->assertExactJson([
            'message' => 'Schema change applied. MySQL changed only the table metadata.',
            'algorithm' => 'INSTANT',
            'redirect' => null,
        ]);
        expect(AuditLog::query()->sole())
            ->action->toBe('schema.add_column')
            ->database_name->toBe('shop')
            ->table_name->toBe('orders')
            ->details->toBe(['column' => 'paid']);
    });

    test('a change that would lock a big table asks for confirmation with 409', function () {
        $this->tables->shouldReceive('apply')->once()->andThrow(new LockingDdlRequiresConfirmationException(5_000_000));

        $response = $this->postJson(route('schema.apply', 'shop'), addColumnPayload());

        $response->assertConflict()
            ->assertJsonPath('needsLockingConfirmation', true)
            ->assertJsonPath('approximateRows', 5_000_000);
    });

    test('confirming lets MySQL copy the table', function () {
        $this->tables->shouldReceive('apply')
            ->once()
            ->withArgs(fn (TableRef $table, AddColumn $change, bool $allowLocking) => $allowLocking === true)
            ->andReturn(new DdlResult(['ALTER …'], 'COPY'));

        $this->postJson(route('schema.apply', 'shop'), [...addColumnPayload(), 'allowLocking' => true])
            ->assertOk()
            ->assertJsonPath('algorithm', 'COPY');
    });

    test('read-only mode is reported as 422', function () {
        $this->tables->shouldReceive('apply')->andThrow(new ReadOnlyModeException);

        $this->postJson(route('schema.apply', 'shop'), addColumnPayload())
            ->assertUnprocessable()
            ->assertJsonPath('message', 'DBNova is in read-only mode. Changes to the MySQL server are disabled.');
    });

    test('renaming, creating and dropping a table say where to go next', function (array $change, string $class, string $redirect) {
        $this->mock(MySQLConnector::class)->shouldReceive('connect')->andReturn(new PDO('sqlite::memory:'))
            ->shouldReceive('matchedUser')->andReturnUsing(fn () => auth()->user()?->username);
        $this->tables->shouldReceive('apply')
            ->withArgs(fn (TableRef $table, object $applied) => $applied instanceof $class)
            ->andReturn(new DdlResult(['…']));

        $this->postJson(route('schema.apply', 'shop'), [
            'table' => 'orders',
            'change' => $change,
            'confirmation' => 'orders',
            'password' => 'Secret@123',
        ])->assertOk()->assertJsonPath('redirect', $redirect);
    })->with([
        'rename' => [['type' => 'rename_table', 'newName' => 'purchases'], RenameTable::class, '/databases/shop/tables/purchases/structure'],
        'create' => [['type' => 'create_table', 'columns' => [['name' => 'id', 'type' => 'int']], 'primaryKey' => ['id']], CreateTable::class, '/databases/shop/tables/orders/structure'],
        'drop' => [['type' => 'drop_table'], DropTable::class, '/databases/shop'],
    ]);
});

describe('destructive changes', function () {
    test('need the table name typed exactly', function () {
        $this->tables->shouldNotReceive('apply');

        $this->postJson(route('schema.apply', 'shop'), [
            'table' => 'orders',
            'change' => ['type' => 'drop_column', 'column' => 'notes'],
            'confirmation' => 'order',
            'password' => 'Secret@123',
        ])->assertUnprocessable()->assertJsonValidationErrors(['confirmation' => 'Type the table name exactly to confirm.']);
    });

    test('need the MySQL password', function () {
        $this->mock(MySQLConnector::class)->shouldReceive('connect')->andThrow(new PDOException('Access denied', 1045));
        $this->tables->shouldNotReceive('apply');

        $this->postJson(route('schema.apply', 'shop'), [
            'table' => 'orders',
            'change' => ['type' => 'truncate_table'],
            'confirmation' => 'orders',
            'password' => 'wrong',
        ])->assertUnprocessable()->assertJsonValidationErrors(['password' => 'The MySQL password is incorrect.']);
    });
});

test('guests cannot preview or apply schema changes', function (string $routeName) {
    auth()->logout();

    $this->postJson(route($routeName, 'shop'), addColumnPayload())->assertUnauthorized();
})->with(['preview' => ['schema.preview'], 'apply' => ['schema.apply']]);

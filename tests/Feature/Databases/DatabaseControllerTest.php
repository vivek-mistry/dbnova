<?php

use App\Database\Adapters\MySQL\MySQLConnector;
use App\Database\DTOs\CollationInfo;
use App\Database\DTOs\DatabaseInfo;
use App\Database\DTOs\TableInfo;
use App\Database\Exceptions\DatabaseOperationException;
use App\Database\Exceptions\ReadOnlyModeException;
use App\Models\AuditLog;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function shopDatabase(int $tables = 2, bool $isSystem = false, string $name = 'shop'): DatabaseInfo
{
    return new DatabaseInfo($name, 'utf8mb4', 'utf8mb4_0900_ai_ci', $tables, 32768, $isSystem);
}

describe('index', function () {
    test('lists every database with deferred collations for the create form', function () {
        $schema = fakeSchemaManager();
        $schema->shouldReceive('databases')->andReturn([shopDatabase(), shopDatabase(38, true, 'mysql')]);
        $schema->shouldReceive('collations')->andReturn([new CollationInfo('utf8mb4_0900_ai_ci', 'utf8mb4', true)]);
        actingAsMySqlUser();

        $response = $this->get(route('databases.index'));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('databases/Index')
            ->has('databases', 2)
            ->where('databases.0', [
                'name' => 'shop',
                'characterSet' => 'utf8mb4',
                'collation' => 'utf8mb4_0900_ai_ci',
                'tableCount' => 2,
                'sizeBytes' => 32768,
                'isSystem' => false,
            ])
            ->where('databases.1.isSystem', true)
            ->missing('collations')
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('collations', [['name' => 'utf8mb4_0900_ai_ci', 'characterSet' => 'utf8mb4', 'isDefault' => true]])
            )
        );
    });

    test('a MySQL failure renders the database error page', function () {
        fakeSchemaManager()->shouldReceive('databases')->andThrow(new DatabaseOperationException('The connection to the MySQL server was lost.'));
        actingAsMySqlUser();

        $response = $this->get(route('databases.index'));

        $response->assertStatus(503);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('DatabaseError')
            ->where('message', 'The connection to the MySQL server was lost.')
        );
    });
});

describe('store', function () {
    test('creates a database, audits it and opens it', function () {
        fakeSchemaManager()->shouldReceive('createDatabase')->once()->with('my_app', 'utf8mb4_unicode_ci');
        $user = actingAsMySqlUser();

        $response = $this->post(route('databases.store'), ['name' => 'my_app', 'collation' => 'utf8mb4_unicode_ci']);

        $response->assertRedirect(route('databases.show', 'my_app'));
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'mysql_username' => $user->username,
            'action' => 'database.create',
            'database_name' => 'my_app',
            'succeeded' => true,
        ]);
    });

    test('a blank collation uses the server default', function () {
        fakeSchemaManager()->shouldReceive('createDatabase')->once()->with('my_app', null);
        actingAsMySqlUser();

        $response = $this->post(route('databases.store'), ['name' => 'my_app', 'collation' => '']);

        $response->assertRedirect(route('databases.show', 'my_app'));
    });

    test('invalid names are rejected before reaching MySQL', function () {
        fakeSchemaManager()->shouldNotReceive('createDatabase');
        actingAsMySqlUser();

        $response = $this->post(route('databases.store'), ['name' => 'a/b']);

        $response->assertSessionHasErrors(['name' => 'The name cannot contain "/", "\\", "." or NUL characters.']);
    });

    test('collation names must be plain identifiers', function () {
        fakeSchemaManager()->shouldNotReceive('createDatabase');
        actingAsMySqlUser();

        $response = $this->post(route('databases.store'), ['name' => 'my_app', 'collation' => 'utf8mb4_bin; DROP DATABASE x']);

        $response->assertSessionHasErrors('collation');
    });

    test('a MySQL error is shown on the name field and audited as a failure', function () {
        fakeSchemaManager()->shouldReceive('createDatabase')
            ->andThrow(new DatabaseOperationException('A database with that name already exists.'));
        actingAsMySqlUser();

        $response = $this->post(route('databases.store'), ['name' => 'shop']);

        $response->assertSessionHasErrors(['name' => 'A database with that name already exists.']);
        expect(AuditLog::query()->sole())
            ->action->toBe('database.create')
            ->succeeded->toBeFalse()
            ->error->toBe('A database with that name already exists.');
    });

    test('read-only mode is reported on the form', function () {
        fakeSchemaManager()->shouldReceive('createDatabase')->andThrow(new ReadOnlyModeException);
        actingAsMySqlUser();

        $response = $this->post(route('databases.store'), ['name' => 'shop']);

        $response->assertSessionHasErrors(['name' => 'DBNova is in read-only mode. Changes to the MySQL server are disabled.']);
    });
});

describe('show', function () {
    test('shows the database, its tables and what blocks a rename', function () {
        $schema = fakeSchemaManager();
        $schema->shouldReceive('database')->with('shop')->andReturn(shopDatabase());
        $schema->shouldReceive('tables')->with('shop')->andReturn([
            new TableInfo('orders', 'table', 'InnoDB', 1200, 65536, 'utf8mb4_0900_ai_ci', 49152, 16384, 4096),
            new TableInfo('order_totals', 'view', null, null, null, null),
        ]);
        $schema->shouldReceive('renameBlockers')->with('shop')->andReturn(['1 view']);
        actingAsMySqlUser();

        $response = $this->get(route('databases.show', 'shop'));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('databases/Show')
            ->where('database.name', 'shop')
            ->has('tables', 2)
            ->where('tables.0', [
                'name' => 'orders',
                'type' => 'table',
                'engine' => 'InnoDB',
                'approximateRows' => 1200,
                'sizeBytes' => 65536,
                'collation' => 'utf8mb4_0900_ai_ci',
                'dataBytes' => 49152,
                'indexBytes' => 16384,
                'freeBytes' => 4096,
            ])
            ->where('renameBlockers', ['1 view'])
        );
    });

    test('returns 404 for a database that does not exist', function () {
        fakeSchemaManager()->shouldReceive('database')->with('missing')->andReturn(null);
        actingAsMySqlUser();

        $response = $this->get(route('databases.show', 'missing'));

        $response->assertNotFound();
    });
});

describe('update', function () {
    test('renames the database, audits it and opens the new name', function () {
        fakeSchemaManager()->shouldReceive('renameDatabase')->once()->with('shop', 'store');
        actingAsMySqlUser();

        $response = $this->patch(route('databases.update', 'shop'), ['name' => 'store']);

        $response->assertRedirect(route('databases.show', 'store'));
        expect(AuditLog::query()->sole())
            ->action->toBe('database.rename')
            ->database_name->toBe('shop')
            ->details->toBe(['new_name' => 'store']);
    });

    test('the new name must differ from the current one', function () {
        fakeSchemaManager()->shouldNotReceive('renameDatabase');
        actingAsMySqlUser();

        $response = $this->patch(route('databases.update', 'shop'), ['name' => 'shop']);

        $response->assertSessionHasErrors(['name' => 'The new name must be different from the current name.']);
    });

    test('a blocked rename shows why', function () {
        fakeSchemaManager()->shouldReceive('renameDatabase')
            ->andThrow(new DatabaseOperationException('"shop" cannot be renamed because it contains 1 view.'));
        actingAsMySqlUser();

        $response = $this->patch(route('databases.update', 'shop'), ['name' => 'store']);

        $response->assertSessionHasErrors(['name' => '"shop" cannot be renamed because it contains 1 view.']);
    });
});

describe('destroy', function () {
    beforeEach(function () {
        $this->connector = $this->mock(MySQLConnector::class);
        $this->connector->shouldReceive('serverLabel')->andReturn('127.0.0.1:3306');
    });

    test('drops the database after the name is typed and the MySQL password confirmed', function () {
        $this->connector->shouldReceive('connect')->once()->with('root', 'Secret@123')->andReturn(new PDO('sqlite::memory:'));
        fakeSchemaManager()->shouldReceive('dropDatabase')->once()->with('shop');
        actingAsMySqlUser(User::factory()->create(['username' => 'root']), 'Secret@123');

        $response = $this->delete(route('databases.destroy', 'shop'), ['confirmation' => 'shop', 'password' => 'Secret@123']);

        $response->assertRedirect(route('databases.index'));
        expect(AuditLog::query()->sole())
            ->action->toBe('database.drop')
            ->database_name->toBe('shop')
            ->succeeded->toBeTrue();
    });

    test('a mistyped name does not drop anything', function () {
        $this->connector->shouldNotReceive('connect');
        fakeSchemaManager()->shouldNotReceive('dropDatabase');
        actingAsMySqlUser();

        $response = $this->delete(route('databases.destroy', 'shop'), ['confirmation' => 'shoe', 'password' => 'Secret@123']);

        $response->assertSessionHasErrors(['confirmation' => 'Type the database name exactly to confirm.']);
    });

    test('a wrong MySQL password does not drop anything', function () {
        $this->connector->shouldReceive('connect')->once()
            ->andThrow(new PDOException('SQLSTATE[HY000] [1045] Access denied', 1045));
        fakeSchemaManager()->shouldNotReceive('dropDatabase');
        actingAsMySqlUser();

        $response = $this->delete(route('databases.destroy', 'shop'), ['confirmation' => 'shop', 'password' => 'wrong']);

        $response->assertSessionHasErrors(['password' => 'The MySQL password is incorrect.']);
        expect(AuditLog::query()->count())->toBe(0);
    });
});

test('guests cannot change databases', function (string $method, string $routeName, array $parameters) {
    fakeSchemaManager()->shouldNotReceive('createDatabase', 'renameDatabase', 'dropDatabase');

    $response = $this->call($method, route($routeName, $parameters), ['name' => 'store', 'confirmation' => 'shop', 'password' => 'x']);

    $response->assertRedirect(route('login'));
})->with([
    'create' => ['POST', 'databases.store', []],
    'rename' => ['PATCH', 'databases.update', ['shop']],
    'drop' => ['DELETE', 'databases.destroy', ['shop']],
]);

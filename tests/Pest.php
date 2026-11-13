<?php

use App\Database\Contracts\DatabaseAdapterInterface;
use App\Database\Contracts\QueryExecutorInterface;
use App\Database\Contracts\RecordManagerInterface;
use App\Database\Contracts\SchemaManagerInterface;
use App\Database\Contracts\TableBrowserInterface;
use App\Database\Contracts\TableManagerInterface;
use App\Models\User;
use App\Services\Auth\MySQLCredentialStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Sign in as a DBNova user whose session holds MySQL credentials, as it
 * would after a successful login.
 */
function actingAsMySqlUser(?User $user = null, string $password = 'secret'): User
{
    $user ??= User::factory()->create();

    test()->actingAs($user)->withSession([]);
    app(MySQLCredentialStore::class)->store($user->username, $password);

    return $user;
}

/**
 * Replace the managed-database adapter with one whose schema manager is a mock.
 */
function fakeSchemaManager(): MockInterface
{
    return fakeDatabaseAdapter()['schema'];
}

/**
 * Replace the managed-database adapter with one whose schema manager, table
 * browser, record manager, table manager and query executor are mocks.
 *
 * @return array{schema: MockInterface, browser: MockInterface, records: MockInterface, tables: MockInterface, queries: MockInterface}
 */
function fakeDatabaseAdapter(): array
{
    $schema = Mockery::mock(SchemaManagerInterface::class);
    $browser = Mockery::mock(TableBrowserInterface::class);
    $records = Mockery::mock(RecordManagerInterface::class);
    $tables = Mockery::mock(TableManagerInterface::class);
    $queries = Mockery::mock(QueryExecutorInterface::class);
    $tables->shouldReceive('columnTypes')->andReturn(['int', 'varchar', 'text'])->byDefault();
    $tables->shouldReceive('engines')->andReturn(['InnoDB'])->byDefault();
    $tables->shouldReceive('foreignKeys')->andReturn([])->byDefault();

    test()->mock(DatabaseAdapterInterface::class, fn (MockInterface $adapter) => $adapter
        ->shouldReceive('schema')->andReturn($schema)
        ->shouldReceive('browser')->andReturn($browser)
        ->shouldReceive('records')->andReturn($records)
        ->shouldReceive('tables')->andReturn($tables)
        ->shouldReceive('queries')->andReturn($queries));

    return ['schema' => $schema, 'browser' => $browser, 'records' => $records, 'tables' => $tables, 'queries' => $queries];
}

/**
 * Credentials for integration tests against a real MySQL server, or null
 * when DBNOVA_TEST_DB_USERNAME is not configured.
 *
 * @return array{username: string, password: string}|null
 */
function mySqlTestCredentials(): ?array
{
    $username = config('dbnova.testing.username');

    return is_string($username) && $username !== ''
        ? ['username' => $username, 'password' => (string) config('dbnova.testing.password')]
        : null;
}

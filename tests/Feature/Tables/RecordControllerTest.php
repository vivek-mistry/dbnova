<?php

use App\Database\DTOs\Page;
use App\Database\DTOs\TableRef;
use App\Database\Exceptions\DatabaseOperationException;
use App\Database\Exceptions\ReadOnlyModeException;
use App\Models\AuditLog;

beforeEach(function () {
    ['browser' => $this->browser, 'records' => $this->records] = fakeDatabaseAdapter();
    $this->user = actingAsMySqlUser();
    $this->args = ['database' => 'shop', 'table' => 'orders'];
});

describe('insert', function () {
    test('inserts the row and audits the column names only', function () {
        $this->records->shouldReceive('insert')
            ->once()
            ->withArgs(fn (TableRef $table, array $values) => $table == new TableRef('shop', 'orders') && $values === ['notes' => 'secret value', 'paid_at' => null]);

        $response = $this->postJson(route('tables.records.store', $this->args), [
            'values' => ['notes' => 'secret value', 'paid_at' => null],
        ]);

        $response->assertCreated()->assertExactJson(['message' => 'Row inserted.']);
        expect(AuditLog::query()->sole())
            ->action->toBe('record.insert')
            ->database_name->toBe('shop')
            ->table_name->toBe('orders')
            ->details->toBe(['columns' => ['notes', 'paid_at']]);
        expect(AuditLog::query()->sole()->toJson())->not->toContain('secret value');
    });

    test('MySQL rejections come back as 422 with the reason and are audited', function () {
        $this->records->shouldReceive('insert')
            ->andThrow(new DatabaseOperationException("MySQL error: Field 'customer' doesn't have a default value"));

        $response = $this->postJson(route('tables.records.store', $this->args), ['values' => []]);

        $response->assertUnprocessable()->assertExactJson(['message' => "MySQL error: Field 'customer' doesn't have a default value"]);
        expect(AuditLog::query()->sole()->succeeded)->toBeFalse();
    });
});

describe('update', function () {
    test('saves the change and returns the row as stored', function () {
        $this->records->shouldReceive('update')
            ->once()
            ->withArgs(fn (TableRef $table, array $key, array $values) => $key === [7] && $values === ['status' => 'paid'])
            ->andReturn([7]);
        $this->browser->shouldReceive('row')->with(Mockery::type(TableRef::class), [7])->andReturn([7, 'paid']);

        $response = $this->patchJson(route('tables.records.update', $this->args), [
            'key' => Page::encodeCursor([7]),
            'values' => ['status' => 'paid'],
        ]);

        $response->assertOk()->assertExactJson(['key' => Page::encodeCursor([7]), 'row' => [7, 'paid']]);
        expect(AuditLog::query()->sole()->details)->toBe(['columns' => ['status']]);
    });

    test('empty strings and surrounding spaces reach MySQL unchanged', function () {
        $this->records->shouldReceive('update')
            ->once()
            ->withArgs(fn (TableRef $table, array $key, array $values) => $values === ['notes' => '', 'title' => '  padded  '])
            ->andReturn([7]);
        $this->browser->shouldReceive('row')->andReturn([7, '', '  padded  ']);

        $response = $this->patchJson(route('tables.records.update', $this->args), [
            'key' => Page::encodeCursor([7]),
            'values' => ['notes' => '', 'title' => '  padded  '],
        ]);

        $response->assertOk();
    });

    test('read-only mode is reported as 422', function () {
        $this->records->shouldReceive('update')->andThrow(new ReadOnlyModeException);

        $response = $this->patchJson(route('tables.records.update', $this->args), [
            'key' => Page::encodeCursor([7]),
            'values' => ['status' => 'paid'],
        ]);

        $response->assertUnprocessable()->assertJsonPath('message', 'DBNova is in read-only mode. Changes to the MySQL server are disabled.');
    });

    test('an invalid row key is rejected before reaching MySQL', function () {
        $this->records->shouldNotReceive('update');

        $response = $this->patchJson(route('tables.records.update', $this->args), [
            'key' => 'not-a-key!',
            'values' => ['status' => 'paid'],
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('key');
    });

    test('values must be text or null', function () {
        $this->records->shouldNotReceive('update');

        $response = $this->patchJson(route('tables.records.update', $this->args), [
            'key' => Page::encodeCursor([7]),
            'values' => ['status' => ['nested' => 'array']],
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['values' => 'The value for "status" must be text or null.']);
    });
});

describe('delete', function () {
    test('deletes the rows by key and audits how many were requested', function () {
        $this->records->shouldReceive('delete')
            ->once()
            ->withArgs(fn (TableRef $table, array $keys) => $keys === [[1], [2]])
            ->andReturn(2);

        $response = $this->deleteJson(route('tables.records.destroy', $this->args), [
            'keys' => [Page::encodeCursor([1]), Page::encodeCursor([2])],
        ]);

        $response->assertOk()->assertExactJson(['deleted' => 2]);
        expect(AuditLog::query()->sole())
            ->action->toBe('record.delete')
            ->details->toBe(['requested' => 2]);
    });

    test('at most 1000 rows can be deleted at once', function () {
        $this->records->shouldNotReceive('delete');

        $response = $this->deleteJson(route('tables.records.destroy', $this->args), [
            'keys' => array_fill(0, 1001, Page::encodeCursor([1])),
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('keys');
    });
});

test('guests cannot change rows', function (string $method, string $routeName) {
    auth()->logout();

    $response = $this->json($method, route($routeName, ['database' => 'shop', 'table' => 'orders']), []);

    $response->assertUnauthorized();
})->with([
    'insert' => ['POST', 'tables.records.store'],
    'update' => ['PATCH', 'tables.records.update'],
    'delete' => ['DELETE', 'tables.records.destroy'],
]);

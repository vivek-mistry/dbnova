<?php

use App\Database\DTOs\ColumnInfo;
use App\Database\DTOs\IndexInfo;
use App\Database\DTOs\Page;
use App\Database\DTOs\PageRequest;
use App\Database\DTOs\TableInfo;
use App\Database\DTOs\TableRef;
use Inertia\Testing\AssertableInertia as Assert;

function ordersTable(): TableInfo
{
    return new TableInfo('orders', 'table', 'InnoDB', 1200, 65536, 'utf8mb4_0900_ai_ci');
}

function idColumn(): ColumnInfo
{
    return new ColumnInfo('id', 'int', 'int unsigned', false, 'PRI', null, 'auto_increment', null, '');
}

test('browsing a table renders its columns, key and first page', function () {
    config(['dbnova.limits.page_size' => 2]);
    ['schema' => $schema, 'browser' => $browser] = fakeDatabaseAdapter();
    $schema->shouldReceive('table')->with('shop', 'orders')->andReturn(ordersTable());
    $browser->shouldReceive('columns')->andReturn([idColumn()]);
    $browser->shouldReceive('keyColumns')->andReturn(['id']);
    $browser->shouldReceive('page')
        ->withArgs(fn (TableRef $table, PageRequest $request) => $table->table === 'orders' && $request->limit === 2 && $request->cursor === null)
        ->andReturn(new Page([[1], [2]], true, 'keyset', [2], null, 1.25));
    actingAsMySqlUser();

    $response = $this->get(route('tables.show', ['database' => 'shop', 'table' => 'orders']));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('tables/Browse')
        ->where('database', 'shop')
        ->where('table.name', 'orders')
        ->where('columns.0.name', 'id')
        ->where('columns.0.isBinary', false)
        ->where('keyColumns', ['id'])
        ->where('pageSize', 2)
        ->where('firstPage', [
            'rows' => [[1], [2]],
            'hasMore' => true,
            'strategy' => 'keyset',
            'nextCursor' => Page::encodeCursor([2]),
            'nextOffset' => null,
            'durationMs' => 1.3,
        ])
    );
});

test('the structure tab shows columns, indexes and the CREATE statement', function () {
    ['schema' => $schema, 'browser' => $browser] = fakeDatabaseAdapter();
    $schema->shouldReceive('table')->andReturn(ordersTable());
    $browser->shouldReceive('columns')->andReturn([idColumn()]);
    $browser->shouldReceive('indexes')->andReturn([new IndexInfo('PRIMARY', ['id'], true, 'BTREE')]);
    $browser->shouldReceive('createStatement')->andReturn('CREATE TABLE `orders` (`id` int unsigned NOT NULL)');
    actingAsMySqlUser();

    $response = $this->get(route('tables.structure', ['database' => 'shop', 'table' => 'orders']));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('tables/Structure')
        ->where('indexes', [['name' => 'PRIMARY', 'columns' => ['id'], 'unique' => true, 'type' => 'BTREE']])
        ->where('createStatement', 'CREATE TABLE `orders` (`id` int unsigned NOT NULL)')
    );
});

test('an unknown table returns 404', function (string $routeName) {
    fakeSchemaManager()->shouldReceive('table')->with('shop', 'missing')->andReturn(null);
    actingAsMySqlUser();

    $response = $this->get(route($routeName, ['database' => 'shop', 'table' => 'missing']));

    $response->assertNotFound();
})->with(['browse' => ['tables.show'], 'structure' => ['tables.structure']]);

test('guests cannot browse tables', function () {
    $response = $this->get(route('tables.show', ['database' => 'shop', 'table' => 'orders']));

    $response->assertRedirect(route('login'));
});

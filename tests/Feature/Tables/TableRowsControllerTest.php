<?php

use App\Database\DTOs\ColumnInfo;
use App\Database\DTOs\Filter;
use App\Database\DTOs\FilterOperator;
use App\Database\DTOs\Page;
use App\Database\DTOs\PageRequest;
use App\Database\DTOs\TableRef;

beforeEach(function () {
    ['browser' => $this->browser] = fakeDatabaseAdapter();
    $this->browser->shouldReceive('columns')->andReturn([
        new ColumnInfo('id', 'int', 'int', false, 'PRI', null, '', null, ''),
        new ColumnInfo('status', 'varchar', 'varchar(20)', false, '', null, '', 20, ''),
    ]);
    actingAsMySqlUser();
});

function rowsUrl(array $query = []): string
{
    return route('tables.rows', ['database' => 'shop', 'table' => 'orders']).'?'.http_build_query($query);
}

test('the next page is fetched by cursor with sort and filters', function () {
    $this->browser->shouldReceive('page')
        ->withArgs(fn (TableRef $table, PageRequest $request) => $request == new PageRequest(
            limit: 50,
            sort: 'id',
            direction: 'desc',
            filters: [new Filter('status', FilterOperator::Equals, 'paid')],
            cursor: [100],
        ))
        ->andReturn(new Page([[99]], false, 'keyset', null, null, 0.4));

    $response = $this->getJson(rowsUrl([
        'limit' => 50,
        'cursor' => Page::encodeCursor([100]),
        'sort' => 'id',
        'direction' => 'desc',
        'filters' => [['column' => 'status', 'operator' => '=', 'value' => 'paid']],
    ]));

    $response->assertExactJson([
        'rows' => [[99]],
        'hasMore' => false,
        'strategy' => 'keyset',
        'nextCursor' => null,
        'nextOffset' => null,
        'durationMs' => 0.4,
    ]);
});

test('unknown sort or filter columns are rejected with 422', function (array $query) {
    $this->browser->shouldNotReceive('page');

    $response = $this->getJson(rowsUrl($query));

    $response->assertUnprocessable()->assertJsonValidationErrors(['columns' => 'Unknown column "missing".']);
})->with([
    'sort' => [['sort' => 'missing']],
    'filter' => [['filters' => [['column' => 'missing', 'operator' => 'is_null']]]],
]);

test('invalid paging input is rejected with 422', function (array $query, string $field) {
    $this->browser->shouldNotReceive('page');

    $response = $this->getJson(rowsUrl($query));

    $response->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'limit too large' => [['limit' => PageRequest::MAX_LIMIT + 1], 'limit'],
    'bad cursor' => [['cursor' => 'not-a-cursor!'], 'cursor'],
    'bad direction' => [['direction' => 'sideways'], 'direction'],
    'unknown operator' => [['filters' => [['column' => 'status', 'operator' => 'regexp']]], 'filters.0.operator'],
]);

test('the exact count applies the filters', function () {
    $this->browser->shouldReceive('count')
        ->withArgs(fn (TableRef $table, array $filters) => $filters == [new Filter('status', FilterOperator::IsNull, '')])
        ->andReturn(1234567);

    $response = $this->getJson(route('tables.count', ['database' => 'shop', 'table' => 'orders']).'?'.http_build_query([
        'filters' => [['column' => 'status', 'operator' => 'is_null']],
    ]));

    $response->assertExactJson(['count' => 1234567]);
});

test('a full cell value is located by the encoded row key', function () {
    $this->browser->shouldReceive('value')
        ->withArgs(fn (TableRef $table, string $column, array $key) => $column === 'status' && $key === [7])
        ->andReturn(['text' => 'paid']);

    $response = $this->getJson(route('tables.value', ['database' => 'shop', 'table' => 'orders']).'?'.http_build_query([
        'column' => 'status',
        'key' => Page::encodeCursor([7]),
    ]));

    $response->assertExactJson(['text' => 'paid']);
});

test('a missing row returns 404 for the value', function () {
    $this->browser->shouldReceive('value')->andReturn(null);

    $response = $this->getJson(route('tables.value', ['database' => 'shop', 'table' => 'orders']).'?'.http_build_query([
        'column' => 'status',
        'key' => Page::encodeCursor([999]),
    ]));

    $response->assertNotFound();
});

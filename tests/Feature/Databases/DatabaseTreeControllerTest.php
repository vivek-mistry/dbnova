<?php

use App\Database\DTOs\DatabaseInfo;
use App\Database\DTOs\TableInfo;
use App\Database\Exceptions\DatabaseOperationException;

test('the tree lists database names', function () {
    fakeSchemaManager()->shouldReceive('databases')->andReturn([
        new DatabaseInfo('mysql', 'utf8mb4', 'utf8mb4_0900_ai_ci', 38, 1, true),
        new DatabaseInfo('shop', 'utf8mb4', 'utf8mb4_0900_ai_ci', 2, 1, false),
    ]);
    actingAsMySqlUser();

    $response = $this->getJson(route('database-tree.index'));

    $response->assertExactJson([
        ['name' => 'mysql', 'isSystem' => true],
        ['name' => 'shop', 'isSystem' => false],
    ]);
});

test('the tree lists the tables and views of one database', function () {
    fakeSchemaManager()->shouldReceive('tables')->with('shop')->andReturn([
        new TableInfo('orders', 'table', 'InnoDB', 10, 1, null),
        new TableInfo('order_totals', 'view', null, null, null, null),
    ]);
    actingAsMySqlUser();

    $response = $this->getJson(route('database-tree.tables', 'shop'));

    $response->assertExactJson([
        ['name' => 'orders', 'type' => 'table'],
        ['name' => 'order_totals', 'type' => 'view'],
    ]);
});

test('a MySQL failure returns 503 with the reason', function () {
    fakeSchemaManager()->shouldReceive('databases')->andThrow(new DatabaseOperationException('The connection to the MySQL server was lost.'));
    actingAsMySqlUser();

    $response = $this->getJson(route('database-tree.index'));

    $response->assertStatus(503)->assertExactJson(['message' => 'The connection to the MySQL server was lost.']);
});

test('guests cannot read the tree', function () {
    $response = $this->getJson(route('database-tree.index'));

    $response->assertUnauthorized();
});

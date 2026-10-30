<?php

use App\Database\Adapters\MySQL\MySQLPageQueryBuilder;
use App\Database\DTOs\ColumnInfo;
use App\Database\DTOs\Filter;
use App\Database\DTOs\FilterOperator;
use App\Database\DTOs\PageRequest;
use App\Database\DTOs\TableRef;
use App\Database\Support\IdentifierQuoter;

function column(string $name, string $dataType = 'int', ?int $maxLength = null): ColumnInfo
{
    return new ColumnInfo($name, $dataType, $dataType, false, '', null, '', $maxLength, '');
}

function pageQuery(array $keyColumns, PageRequest $request, ?array $columns = null): array
{
    return (new MySQLPageQueryBuilder(new IdentifierQuoter))->page(
        new TableRef('shop', 'orders'),
        $columns ?? [column('id'), column('status', 'varchar', 20)],
        $keyColumns,
        $request,
    );
}

describe('keyset paging', function () {
    test('the first page orders by the key and fetches one extra row', function () {
        $query = pageQuery(['id'], new PageRequest(limit: 100));

        expect($query['strategy'])->toBe('keyset');
        expect($query['sql'])->toBe('SELECT `id` AS c0, `status` AS c1, `id` AS k0 FROM `shop`.`orders` ORDER BY `id` ASC LIMIT 101');
        expect($query['bindings'])->toBe([]);
    });

    test('later pages start after the cursor', function () {
        $query = pageQuery(['id'], new PageRequest(limit: 100, cursor: [500]));

        expect($query['sql'])->toContain('WHERE `id` > ? ORDER BY `id` ASC LIMIT 101');
        expect($query['bindings'])->toBe([500]);
    });

    test('descending order by the key pages backwards', function () {
        $query = pageQuery(['id'], new PageRequest(limit: 100, sort: 'id', direction: 'desc', cursor: [500]));

        expect($query['strategy'])->toBe('keyset');
        expect($query['sql'])->toContain('WHERE `id` < ? ORDER BY `id` DESC');
    });

    test('composite keys compare as a row value', function () {
        $query = pageQuery(['order_id', 'line'], new PageRequest(limit: 50, cursor: [7, 3]), [column('order_id'), column('line')]);

        expect($query['sql'])->toContain('WHERE (`order_id`, `line`) > (?, ?) ORDER BY `order_id` ASC, `line` ASC LIMIT 51');
        expect($query['bindings'])->toBe([7, 3]);
    });

    test('a cursor that does not match the key is rejected', function () {
        expect(fn () => pageQuery(['order_id', 'line'], new PageRequest(limit: 50, cursor: [7]), [column('order_id'), column('line')]))
            ->toThrow(InvalidArgumentException::class, 'The page cursor does not match the table key.');
    });
});

describe('offset paging', function () {
    test('sorting by a non-key column falls back to offset with the key as tiebreaker', function () {
        $query = pageQuery(['id'], new PageRequest(limit: 100, sort: 'status', direction: 'desc', offset: 200, cursor: [5]));

        expect($query['strategy'])->toBe('offset');
        expect($query['sql'])->toEndWith('ORDER BY `status` DESC, `id` ASC LIMIT 101 OFFSET 200');
        expect($query['bindings'])->toBe([]);
    });

    test('tables without a key page by offset without an order', function () {
        $query = pageQuery([], new PageRequest(limit: 100, offset: 100));

        expect($query['strategy'])->toBe('offset');
        expect($query['sql'])->toBe('SELECT `id` AS c0, `status` AS c1 FROM `shop`.`orders` LIMIT 101 OFFSET 100');
    });
});

test('the limit is capped at the maximum page size', function () {
    $query = pageQuery(['id'], new PageRequest(limit: 50_000));

    expect($query['sql'])->toEndWith('LIMIT '.(PageRequest::MAX_LIMIT + 1));
});

test('long text is previewed and binary columns send only their length', function () {
    $query = pageQuery(['id'], new PageRequest(limit: 10), [
        column('id'),
        column('notes', 'text', 65535),
        column('title', 'varchar', 1000),
        column('code', 'varchar', 20),
        column('attachment', 'blob', 65535),
    ]);

    expect($query['sql'])->toStartWith('SELECT `id` AS c0, LEFT(`notes`, 257) AS c1, LEFT(`title`, 257) AS c2, `code` AS c3, OCTET_LENGTH(`attachment`) AS c4,');
});

test('filters become bound conditions', function (FilterOperator $operator, ?string $value, string $condition, array $bindings) {
    $query = pageQuery(['id'], new PageRequest(limit: 10, filters: [new Filter('status', $operator, $value)]));

    expect($query['sql'])->toContain("WHERE {$condition} ORDER BY");
    expect($query['bindings'])->toBe($bindings);
})->with([
    'equals' => [FilterOperator::Equals, 'paid', '`status` = ?', ['paid']],
    'not equals' => [FilterOperator::NotEquals, 'paid', '`status` != ?', ['paid']],
    'like' => [FilterOperator::Like, '%ai%', '`status` LIKE ?', ['%ai%']],
    'not like' => [FilterOperator::NotLike, '%ai%', '`status` NOT LIKE ?', ['%ai%']],
    'greater than' => [FilterOperator::GreaterThan, '5', '`status` > ?', ['5']],
    'less than or equal' => [FilterOperator::LessThanOrEqual, '5', '`status` <= ?', ['5']],
    'is null' => [FilterOperator::IsNull, null, '`status` IS NULL', []],
    'is not null' => [FilterOperator::IsNotNull, null, '`status` IS NOT NULL', []],
]);

test('filters and the keyset cursor combine with AND, filter bindings first', function () {
    $query = pageQuery(['id'], new PageRequest(limit: 10, filters: [new Filter('status', FilterOperator::Equals, 'paid')], cursor: [42]));

    expect($query['sql'])->toContain('WHERE `status` = ? AND `id` > ?');
    expect($query['bindings'])->toBe(['paid', 42]);
});

test('unknown columns in sort or filters are rejected before any SQL is built', function (PageRequest $request) {
    expect(fn () => pageQuery(['id'], $request))->toThrow(InvalidArgumentException::class, 'Unknown column');
})->with([
    'sort' => [new PageRequest(limit: 10, sort: 'id`; DROP TABLE orders; --')],
    'filter' => [new PageRequest(limit: 10, filters: [new Filter('missing', FilterOperator::IsNull)])],
]);

test('column names with backticks are escaped in the select list', function () {
    $query = pageQuery([], new PageRequest(limit: 10), [column('we`ird')]);

    expect($query['sql'])->toStartWith('SELECT `we``ird` AS c0 FROM');
});

test('count applies the same filters', function () {
    $query = (new MySQLPageQueryBuilder(new IdentifierQuoter))->count(
        new TableRef('shop', 'orders'),
        [column('id'), column('status', 'varchar', 20)],
        [new Filter('status', FilterOperator::Equals, 'paid')],
    );

    expect($query)->toBe(['sql' => 'SELECT COUNT(*) FROM `shop`.`orders` WHERE `status` = ?', 'bindings' => ['paid']]);
});

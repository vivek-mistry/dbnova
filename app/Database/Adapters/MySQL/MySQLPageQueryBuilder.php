<?php

namespace App\Database\Adapters\MySQL;

use App\Database\DTOs\ColumnInfo;
use App\Database\DTOs\Filter;
use App\Database\DTOs\FilterOperator;
use App\Database\DTOs\PageRequest;
use App\Database\DTOs\TableRef;
use App\Database\Support\IdentifierQuoter;
use InvalidArgumentException;

/**
 * Builds the SELECT for one page of table rows.
 *
 * Keyset paging (WHERE key > last key) is used when the table has a key and
 * the page is unsorted or sorted by that key, so deep pages cost the same as
 * the first. Otherwise it falls back to LIMIT/OFFSET.
 *
 * Selected columns are aliased c0..cN (cells) and k0..kN (raw key values for
 * the next cursor), so unusual column names never collide.
 */
class MySQLPageQueryBuilder
{
    public function __construct(private IdentifierQuoter $quoter) {}

    /**
     * @param  list<ColumnInfo>  $columns
     * @param  list<string>  $keyColumns
     * @return array{sql: string, bindings: list<mixed>, strategy: 'keyset'|'offset'}
     *
     * @throws InvalidArgumentException when the request names an unknown column.
     */
    public function page(TableRef $table, array $columns, array $keyColumns, PageRequest $request): array
    {
        $columnNames = array_map(fn (ColumnInfo $column): string => $column->name, $columns);
        $this->assertKnownColumns($columnNames, $request);

        $useKeyset = $keyColumns !== [] && ($request->sort === null || $request->sort === $keyColumns[0]);
        $direction = $request->direction === 'desc' ? 'DESC' : 'ASC';

        $select = [];

        foreach ($columns as $index => $column) {
            $select[] = $this->cellExpression($column)." AS c{$index}";
        }

        foreach ($keyColumns as $index => $keyColumn) {
            $select[] = $this->quoter->quote($keyColumn)." AS k{$index}";
        }

        [$conditions, $bindings] = $this->filterConditions($request->filters);

        if ($useKeyset && $request->cursor !== null) {
            if (count($request->cursor) !== count($keyColumns)) {
                throw new InvalidArgumentException('The page cursor does not match the table key.');
            }

            $conditions[] = $this->keysetCondition($keyColumns, $direction);
            array_push($bindings, ...$request->cursor);
        }

        $sql = 'SELECT '.implode(', ', $select).' FROM '.$this->from($table);

        if ($conditions !== []) {
            $sql .= ' WHERE '.implode(' AND ', $conditions);
        }

        $orderBy = $this->orderBy($useKeyset, $keyColumns, $request->sort, $direction);

        if ($orderBy !== []) {
            $sql .= ' ORDER BY '.implode(', ', $orderBy);
        }

        $sql .= ' LIMIT '.(min($request->limit, PageRequest::MAX_LIMIT) + 1);

        if (! $useKeyset && $request->offset > 0) {
            $sql .= ' OFFSET '.$request->offset;
        }

        return ['sql' => $sql, 'bindings' => $bindings, 'strategy' => $useKeyset ? 'keyset' : 'offset'];
    }

    /**
     * @param  list<ColumnInfo>  $columns
     * @param  list<Filter>  $filters
     * @return array{sql: string, bindings: list<mixed>}
     */
    public function count(TableRef $table, array $columns, array $filters): array
    {
        $columnNames = array_map(fn (ColumnInfo $column): string => $column->name, $columns);
        $this->assertKnownColumns($columnNames, new PageRequest(limit: 1, filters: $filters));

        [$conditions, $bindings] = $this->filterConditions($filters);

        $sql = 'SELECT COUNT(*) FROM '.$this->from($table);

        if ($conditions !== []) {
            $sql .= ' WHERE '.implode(' AND ', $conditions);
        }

        return ['sql' => $sql, 'bindings' => $bindings];
    }

    public function from(TableRef $table): string
    {
        return $this->quoter->quote($table->database).'.'.$this->quoter->quote($table->table);
    }

    private function cellExpression(ColumnInfo $column): string
    {
        $quoted = $this->quoter->quote($column->name);

        if ($column->isBinary()) {
            return "OCTET_LENGTH({$quoted})";
        }

        if ($column->needsPreview()) {
            // One extra character tells the reader the value was cut.
            return "LEFT({$quoted}, ".(ColumnInfo::PREVIEW_LENGTH + 1).')';
        }

        return $quoted;
    }

    /**
     * @param  list<Filter>  $filters
     * @return array{0: list<string>, 1: list<mixed>}
     */
    private function filterConditions(array $filters): array
    {
        $conditions = [];
        $bindings = [];

        foreach ($filters as $filter) {
            $column = $this->quoter->quote($filter->column);

            $conditions[] = match ($filter->operator) {
                FilterOperator::IsNull => "{$column} IS NULL",
                FilterOperator::IsNotNull => "{$column} IS NOT NULL",
                FilterOperator::Like => "{$column} LIKE ?",
                FilterOperator::NotLike => "{$column} NOT LIKE ?",
                default => "{$column} {$filter->operator->value} ?",
            };

            if ($filter->operator->needsValue()) {
                $bindings[] = $filter->value;
            }
        }

        return [$conditions, $bindings];
    }

    /**
     * @param  list<string>  $keyColumns
     */
    private function keysetCondition(array $keyColumns, string $direction): string
    {
        $comparison = $direction === 'DESC' ? '<' : '>';
        $quoted = array_map(fn (string $column): string => $this->quoter->quote($column), $keyColumns);
        $placeholders = implode(', ', array_fill(0, count($keyColumns), '?'));

        return count($keyColumns) === 1
            ? "{$quoted[0]} {$comparison} ?"
            : '('.implode(', ', $quoted).") {$comparison} ({$placeholders})";
    }

    /**
     * @param  list<string>  $keyColumns
     * @return list<string>
     */
    private function orderBy(bool $useKeyset, array $keyColumns, ?string $sort, string $direction): array
    {
        if ($useKeyset) {
            return array_map(fn (string $column): string => $this->quoter->quote($column).' '.$direction, $keyColumns);
        }

        $orderBy = $sort === null ? [] : [$this->quoter->quote($sort).' '.$direction];

        // A key tiebreaker keeps OFFSET pages stable when sort values repeat.
        foreach ($keyColumns as $keyColumn) {
            $orderBy[] = $this->quoter->quote($keyColumn).' ASC';
        }

        return $orderBy;
    }

    /**
     * @param  list<string>  $columnNames
     */
    private function assertKnownColumns(array $columnNames, PageRequest $request): void
    {
        $requested = array_map(fn (Filter $filter): string => $filter->column, $request->filters);

        if ($request->sort !== null) {
            $requested[] = $request->sort;
        }

        foreach ($requested as $column) {
            if (! in_array($column, $columnNames, true)) {
                throw new InvalidArgumentException("Unknown column \"{$column}\".");
            }
        }
    }
}

<?php

namespace App\Database\Contracts;

use App\Database\DTOs\ColumnInfo;
use App\Database\DTOs\Filter;
use App\Database\DTOs\IndexInfo;
use App\Database\DTOs\Page;
use App\Database\DTOs\PageRequest;
use App\Database\DTOs\TableRef;
use App\Database\Exceptions\DatabaseOperationException;

/**
 * Read access to table rows and structure. Every method may throw a
 * DatabaseOperationException.
 */
interface TableBrowserInterface
{
    /**
     * Columns in table order. Empty when the table does not exist.
     *
     * @return list<ColumnInfo>
     *
     * @throws DatabaseOperationException
     */
    public function columns(TableRef $table): array;

    /**
     * The columns that uniquely identify a row: the primary key, or the first
     * unique index whose columns are all NOT NULL. Empty when there is none.
     *
     * @return list<string>
     *
     * @throws DatabaseOperationException
     */
    public function keyColumns(TableRef $table): array;

    /**
     * @return list<IndexInfo>
     *
     * @throws DatabaseOperationException
     */
    public function indexes(TableRef $table): array;

    /**
     * @throws DatabaseOperationException
     */
    public function createStatement(TableRef $table): string;

    /**
     * @throws DatabaseOperationException
     */
    public function page(TableRef $table, PageRequest $request): Page;

    /**
     * One row's cells, located by its key values, or null when it does not exist.
     *
     * @param  list<int|float|string|null>  $keyValues
     * @return list<mixed>|null
     *
     * @throws DatabaseOperationException
     */
    public function row(TableRef $table, array $keyValues): ?array;

    /**
     * Exact number of rows matching the filters.
     *
     * @param  list<Filter>  $filters
     *
     * @throws DatabaseOperationException
     */
    public function count(TableRef $table, array $filters = []): int;

    /**
     * The full value of one cell, located by the row's key values. Text is
     * returned as ['text' => ...]; binary as ['bytes' => length, 'hex' => first KB].
     *
     * @param  list<int|float|string|null>  $keyValues
     * @return array{text: string}|array{bytes: int, hex: string}|null
     *
     * @throws DatabaseOperationException
     */
    public function value(TableRef $table, string $column, array $keyValues): ?array;
}

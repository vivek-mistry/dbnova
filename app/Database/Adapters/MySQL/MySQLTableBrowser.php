<?php

namespace App\Database\Adapters\MySQL;

use App\Database\Contracts\TableBrowserInterface;
use App\Database\DTOs\ColumnInfo;
use App\Database\DTOs\IndexInfo;
use App\Database\DTOs\Page;
use App\Database\DTOs\PageRequest;
use App\Database\DTOs\TableRef;
use App\Database\Exceptions\DatabaseOperationException;
use App\Database\Support\IdentifierQuoter;
use InvalidArgumentException;

class MySQLTableBrowser implements TableBrowserInterface
{
    /**
     * Integers beyond this cannot be represented exactly in JavaScript.
     */
    private const int MAX_SAFE_INTEGER = 9007199254740991;

    private const int HEX_PREVIEW_BYTES = 1024;

    public function __construct(
        private MySQLConnection $connection,
        private MySQLPageQueryBuilder $queryBuilder,
        private IdentifierQuoter $quoter,
    ) {}

    public function columns(TableRef $table): array
    {
        $rows = $this->connection->select(
            'SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY, COLUMN_DEFAULT, EXTRA,
                CHARACTER_MAXIMUM_LENGTH, COLUMN_COMMENT
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
            ORDER BY ORDINAL_POSITION',
            [$table->database, $table->table],
        );

        return array_map(fn (array $row): ColumnInfo => new ColumnInfo(
            name: (string) $row['COLUMN_NAME'],
            dataType: strtolower((string) $row['DATA_TYPE']),
            columnType: (string) $row['COLUMN_TYPE'],
            nullable: $row['IS_NULLABLE'] === 'YES',
            key: (string) $row['COLUMN_KEY'],
            default: $row['COLUMN_DEFAULT'] === null ? null : (string) $row['COLUMN_DEFAULT'],
            extra: (string) $row['EXTRA'],
            maxLength: $row['CHARACTER_MAXIMUM_LENGTH'] === null ? null : (int) $row['CHARACTER_MAXIMUM_LENGTH'],
            comment: (string) $row['COLUMN_COMMENT'],
        ), $rows);
    }

    public function keyColumns(TableRef $table): array
    {
        $nullableColumns = array_map(
            fn (ColumnInfo $column): string => $column->name,
            array_filter($this->columns($table), fn (ColumnInfo $column): bool => $column->nullable),
        );

        $indexes = $this->indexes($table);

        foreach ($indexes as $index) {
            if ($index->name === 'PRIMARY') {
                return $index->columns;
            }
        }

        foreach ($indexes as $index) {
            if ($index->unique && array_intersect($index->columns, $nullableColumns) === []) {
                return $index->columns;
            }
        }

        return [];
    }

    public function indexes(TableRef $table): array
    {
        $rows = $this->connection->select(
            'SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE, INDEX_TYPE
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
            ORDER BY INDEX_NAME = \'PRIMARY\' DESC, INDEX_NAME, SEQ_IN_INDEX',
            [$table->database, $table->table],
        );

        $indexes = [];

        foreach ($rows as $row) {
            $name = (string) $row['INDEX_NAME'];
            $indexes[$name] ??= ['columns' => [], 'unique' => (int) $row['NON_UNIQUE'] === 0, 'type' => (string) $row['INDEX_TYPE']];
            $indexes[$name]['columns'][] = (string) $row['COLUMN_NAME'];
        }

        return array_map(
            fn (string $name, array $index): IndexInfo => new IndexInfo($name, $index['columns'], $index['unique'], $index['type']),
            array_keys($indexes),
            array_values($indexes),
        );
    }

    public function createStatement(TableRef $table): string
    {
        $row = $this->connection->select('SHOW CREATE TABLE '.$this->queryBuilder->from($table))[0] ?? [];

        return (string) ($row['Create Table'] ?? $row['Create View'] ?? '');
    }

    public function page(TableRef $table, PageRequest $request): Page
    {
        $columns = $this->columns($table);
        $keyColumns = $this->keyColumns($table);
        $query = $this->build(fn () => $this->queryBuilder->page($table, $columns, $keyColumns, $request));

        $startedAt = hrtime(true);
        $rows = $this->connection->select($query['sql'], $query['bindings']);
        $durationMs = (hrtime(true) - $startedAt) / 1_000_000;

        $limit = min($request->limit, PageRequest::MAX_LIMIT);
        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $lastRow = $rows === [] ? null : $rows[array_key_last($rows)];

        return new Page(
            rows: array_map(fn (array $row): array => $this->encodeRow($row, $columns), $rows),
            hasMore: $hasMore,
            strategy: $query['strategy'],
            nextCursor: $query['strategy'] === 'keyset' && $hasMore && $lastRow !== null
                ? array_map(fn (int $index): mixed => $lastRow["k{$index}"], array_keys($keyColumns))
                : null,
            nextOffset: $query['strategy'] === 'offset' && $hasMore ? $request->offset + $limit : null,
            durationMs: $durationMs,
        );
    }

    public function count(TableRef $table, array $filters = []): int
    {
        $query = $this->build(fn () => $this->queryBuilder->count($table, $this->columns($table), $filters));

        return (int) $this->connection->scalar($query['sql'], $query['bindings']);
    }

    public function value(TableRef $table, string $column, array $keyValues): ?array
    {
        $columnInfo = collect($this->columns($table))->first(fn (ColumnInfo $info): bool => $info->name === $column);
        $keyColumns = $this->keyColumns($table);

        if ($columnInfo === null || $keyColumns === [] || count($keyColumns) !== count($keyValues)) {
            return null;
        }

        $conditions = implode(' AND ', array_map(fn (string $keyColumn): string => $this->quoter->quote($keyColumn).' = ?', $keyColumns));

        $value = $this->connection->scalar(
            'SELECT '.$this->quoter->quote($column).' FROM '.$this->queryBuilder->from($table).' WHERE '.$conditions.' LIMIT 1',
            $keyValues,
        );

        if ($value === null) {
            return null;
        }

        $value = (string) $value;

        return $columnInfo->isBinary()
            ? ['bytes' => strlen($value), 'hex' => bin2hex(substr($value, 0, self::HEX_PREVIEW_BYTES))]
            : ['text' => $value];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<ColumnInfo>  $columns
     * @return list<mixed>
     */
    private function encodeRow(array $row, array $columns): array
    {
        $cells = [];

        foreach ($columns as $index => $column) {
            $value = $row["c{$index}"];

            $cells[] = match (true) {
                $value === null => null,
                $column->isBinary() => ['binary' => (int) $value],
                $column->needsPreview() && mb_strlen((string) $value) > ColumnInfo::PREVIEW_LENGTH => [
                    'preview' => mb_substr((string) $value, 0, ColumnInfo::PREVIEW_LENGTH),
                    'truncated' => true,
                ],
                is_int($value) && abs($value) > self::MAX_SAFE_INTEGER => (string) $value,
                default => $value,
            };
        }

        return $cells;
    }

    /**
     * @template TQuery
     *
     * @param  callable(): TQuery  $build
     * @return TQuery
     */
    private function build(callable $build): mixed
    {
        try {
            return $build();
        } catch (InvalidArgumentException $exception) {
            throw new DatabaseOperationException($exception->getMessage(), 0, $exception);
        }
    }
}

<?php

namespace App\Database\Adapters\MySQL;

use App\Database\Contracts\RecordManagerInterface;
use App\Database\DTOs\ColumnInfo;
use App\Database\DTOs\TableRef;
use App\Database\Exceptions\DatabaseOperationException;
use App\Database\Support\IdentifierQuoter;
use App\Database\Support\ReadOnlyGuard;

class MySQLRecordManager implements RecordManagerInterface
{
    /**
     * Rows deleted per DELETE statement.
     */
    private const int DELETE_CHUNK_SIZE = 500;

    public function __construct(
        private MySQLConnection $connection,
        private MySQLTableBrowser $browser,
        private MySQLPageQueryBuilder $queryBuilder,
        private IdentifierQuoter $quoter,
        private ReadOnlyGuard $readOnlyGuard,
    ) {}

    public function insert(TableRef $table, array $values): void
    {
        $this->readOnlyGuard->assertWritable();
        $this->assertEditableColumns($table, array_keys($values));

        $sql = $values === []
            ? 'INSERT INTO '.$this->queryBuilder->from($table).' () VALUES ()'
            : 'INSERT INTO '.$this->queryBuilder->from($table)
                .' ('.implode(', ', array_map($this->quoter->quote(...), array_keys($values))).')'
                .' VALUES ('.implode(', ', array_fill(0, count($values), '?')).')';

        $this->connection->statement($sql, array_values($values));
    }

    public function update(TableRef $table, array $keyValues, array $values): array
    {
        $this->readOnlyGuard->assertWritable();
        $keyColumns = $this->keyColumnsOrFail($table);

        if ($values === []) {
            throw new DatabaseOperationException('There are no changes to save.');
        }

        $this->assertEditableColumns($table, array_keys($values));
        $this->assertKeyMatches($keyColumns, $keyValues);

        $assignments = implode(', ', array_map(fn (string $column): string => $this->quoter->quote($column).' = ?', array_keys($values)));

        $matched = $this->connection->affectingStatement(
            'UPDATE '.$this->queryBuilder->from($table).' SET '.$assignments.' WHERE '.$this->keyCondition($keyColumns).' LIMIT 1',
            [...array_values($values), ...$keyValues],
        );

        if ($matched === 0) {
            throw new DatabaseOperationException('The row no longer exists. It may have been changed or deleted by someone else.');
        }

        return array_map(
            fn (string $keyColumn, int|float|string|null $oldValue): int|float|string|null => array_key_exists($keyColumn, $values) ? $values[$keyColumn] : $oldValue,
            $keyColumns,
            $keyValues,
        );
    }

    public function delete(TableRef $table, array $keys): int
    {
        $this->readOnlyGuard->assertWritable();
        $keyColumns = $this->keyColumnsOrFail($table);

        foreach ($keys as $keyValues) {
            $this->assertKeyMatches($keyColumns, $keyValues);
        }

        return $this->connection->transaction(function () use ($table, $keys, $keyColumns): int {
            $deleted = 0;

            foreach (array_chunk($keys, self::DELETE_CHUNK_SIZE) as $chunk) {
                $conditions = implode(' OR ', array_fill(0, count($chunk), '('.$this->keyCondition($keyColumns).')'));

                $deleted += $this->connection->affectingStatement(
                    'DELETE FROM '.$this->queryBuilder->from($table).' WHERE '.$conditions,
                    array_merge(...$chunk),
                );
            }

            return $deleted;
        });
    }

    /**
     * @return list<string>
     *
     * @throws DatabaseOperationException
     */
    private function keyColumnsOrFail(TableRef $table): array
    {
        $keyColumns = $this->browser->keyColumns($table);

        if ($keyColumns === []) {
            throw new DatabaseOperationException(
                "\"{$table->table}\" has no primary or unique key, so DBNova cannot safely tell its rows apart. Add a key, or change it with SQL.",
            );
        }

        return $keyColumns;
    }

    /**
     * @param  list<string>  $columnNames
     *
     * @throws DatabaseOperationException
     */
    private function assertEditableColumns(TableRef $table, array $columnNames): void
    {
        $columns = collect($this->browser->columns($table))->keyBy(fn (ColumnInfo $column): string => $column->name);

        if ($columns->isEmpty()) {
            throw new DatabaseOperationException("The table \"{$table->table}\" does not exist.");
        }

        foreach ($columnNames as $columnName) {
            $column = $columns->get($columnName) ?? throw new DatabaseOperationException("Unknown column \"{$columnName}\".");

            if ($column->isBinary()) {
                throw new DatabaseOperationException("\"{$columnName}\" holds binary data, which cannot be edited as text.");
            }

            if (str_contains(strtoupper($column->extra), 'GENERATED')) {
                throw new DatabaseOperationException("\"{$columnName}\" is a generated column and cannot be set.");
            }
        }
    }

    /**
     * @param  list<string>  $keyColumns
     * @param  list<int|float|string|null>  $keyValues
     *
     * @throws DatabaseOperationException
     */
    private function assertKeyMatches(array $keyColumns, array $keyValues): void
    {
        if (count($keyValues) !== count($keyColumns)) {
            throw new DatabaseOperationException('The row key does not match the table key.');
        }
    }

    /**
     * @param  list<string>  $keyColumns
     */
    private function keyCondition(array $keyColumns): string
    {
        return implode(' AND ', array_map(fn (string $column): string => $this->quoter->quote($column).' = ?', $keyColumns));
    }
}

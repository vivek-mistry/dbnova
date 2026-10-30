<?php

namespace App\Database\Contracts;

use App\Database\DTOs\TableRef;
use App\Database\Exceptions\DatabaseOperationException;

/**
 * Inserts, updates and deletes rows. Updates and deletes locate rows by the
 * table's primary or unique key, so tables without one are refused. Every
 * method throws a ReadOnlyModeException in read-only mode.
 */
interface RecordManagerInterface
{
    /**
     * Insert one row. Columns left out use their defaults.
     *
     * @param  array<string, string|null>  $values
     *
     * @throws DatabaseOperationException
     */
    public function insert(TableRef $table, array $values): void;

    /**
     * Update one row and return its key values afterwards (they change when
     * a key column is edited).
     *
     * @param  list<int|float|string|null>  $keyValues
     * @param  array<string, string|null>  $values
     * @return list<int|float|string|null>
     *
     * @throws DatabaseOperationException when the row no longer exists or MySQL rejects the change.
     */
    public function update(TableRef $table, array $keyValues, array $values): array;

    /**
     * Delete rows by key and return how many were deleted.
     *
     * @param  list<list<int|float|string|null>>  $keys
     *
     * @throws DatabaseOperationException
     */
    public function delete(TableRef $table, array $keys): int;
}

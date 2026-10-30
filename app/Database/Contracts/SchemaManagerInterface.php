<?php

namespace App\Database\Contracts;

use App\Database\DTOs\CollationInfo;
use App\Database\DTOs\DatabaseInfo;
use App\Database\DTOs\TableInfo;
use App\Database\Exceptions\DatabaseOperationException;

/**
 * Every method may throw a DatabaseOperationException; mutating methods
 * throw a ReadOnlyModeException when DBNova is in read-only mode.
 */
interface SchemaManagerInterface
{
    /**
     * @return list<DatabaseInfo>
     *
     * @throws DatabaseOperationException
     */
    public function databases(): array;

    /**
     * @throws DatabaseOperationException
     */
    public function database(string $name): ?DatabaseInfo;

    /**
     * @return list<TableInfo>
     *
     * @throws DatabaseOperationException
     */
    public function tables(string $database): array;

    /**
     * @throws DatabaseOperationException
     */
    public function table(string $database, string $table): ?TableInfo;

    /**
     * @return list<CollationInfo>
     *
     * @throws DatabaseOperationException
     */
    public function collations(): array;

    /**
     * @throws DatabaseOperationException
     */
    public function createDatabase(string $name, ?string $collation = null): void;

    /**
     * Rename a database by moving its tables to a new database. Databases
     * containing views, routines, events or triggers cannot be renamed.
     *
     * @throws DatabaseOperationException
     */
    public function renameDatabase(string $from, string $to): void;

    /**
     * Describe the objects that prevent a database from being renamed.
     *
     * @return list<string>
     *
     * @throws DatabaseOperationException
     */
    public function renameBlockers(string $database): array;

    /**
     * @throws DatabaseOperationException
     */
    public function dropDatabase(string $name): void;
}

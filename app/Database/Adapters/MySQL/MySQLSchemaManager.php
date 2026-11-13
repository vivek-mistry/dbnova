<?php

namespace App\Database\Adapters\MySQL;

use App\Database\Contracts\SchemaManagerInterface;
use App\Database\DTOs\CollationInfo;
use App\Database\DTOs\DatabaseInfo;
use App\Database\DTOs\TableInfo;
use App\Database\Exceptions\DatabaseOperationException;
use App\Database\Support\IdentifierQuoter;
use App\Database\Support\ReadOnlyGuard;

class MySQLSchemaManager implements SchemaManagerInterface
{
    /**
     * Databases that belong to the MySQL server itself.
     *
     * @var list<string>
     */
    public const array SYSTEM_DATABASES = ['information_schema', 'mysql', 'performance_schema', 'sys'];

    public function __construct(
        private MySQLConnection $connection,
        private IdentifierQuoter $quoter,
        private ReadOnlyGuard $readOnlyGuard,
    ) {}

    public function databases(): array
    {
        return array_map($this->toDatabaseInfo(...), $this->databaseRows());
    }

    public function database(string $name): ?DatabaseInfo
    {
        $row = $this->databaseRows($name)[0] ?? null;

        return $row === null ? null : $this->toDatabaseInfo($row);
    }

    public function tables(string $database): array
    {
        return array_map($this->toTableInfo(...), $this->tableRows($database));
    }

    public function table(string $database, string $table): ?TableInfo
    {
        $row = $this->tableRows($database, $table)[0] ?? null;

        return $row === null ? null : $this->toTableInfo($row);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tableRows(string $database, ?string $table = null): array
    {
        return $this->connection->select(
            'SELECT TABLE_NAME, TABLE_TYPE, ENGINE, TABLE_ROWS, DATA_LENGTH + INDEX_LENGTH AS SIZE_BYTES, TABLE_COLLATION
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = ?'.($table === null ? '' : ' AND TABLE_NAME = ?').'
            ORDER BY TABLE_NAME',
            $table === null ? [$database] : [$database, $table],
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function toTableInfo(array $row): TableInfo
    {
        return new TableInfo(
            name: (string) $row['TABLE_NAME'],
            type: $row['TABLE_TYPE'] === 'VIEW' ? 'view' : 'table',
            engine: $row['ENGINE'] === null ? null : (string) $row['ENGINE'],
            approximateRows: $row['TABLE_ROWS'] === null ? null : (int) $row['TABLE_ROWS'],
            sizeBytes: $row['SIZE_BYTES'] === null ? null : (int) $row['SIZE_BYTES'],
            collation: $row['TABLE_COLLATION'] === null ? null : (string) $row['TABLE_COLLATION'],
        );
    }

    public function columnsByTable(string $database): array
    {
        $rows = $this->connection->select(
            'SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = ?
            ORDER BY TABLE_NAME, ORDINAL_POSITION
            LIMIT 20000',
            [$database],
        );

        $tables = [];

        foreach ($rows as $row) {
            $tables[(string) $row['TABLE_NAME']][] = (string) $row['COLUMN_NAME'];
        }

        return $tables;
    }

    public function collations(): array
    {
        $rows = $this->connection->select(
            'SELECT COLLATION_NAME, CHARACTER_SET_NAME, IS_DEFAULT
            FROM information_schema.COLLATIONS
            ORDER BY CHARACTER_SET_NAME, COLLATION_NAME',
        );

        return array_map(fn (array $row): CollationInfo => new CollationInfo(
            name: (string) $row['COLLATION_NAME'],
            characterSet: (string) $row['CHARACTER_SET_NAME'],
            isDefault: $row['IS_DEFAULT'] === 'Yes',
        ), $rows);
    }

    public function createDatabase(string $name, ?string $collation = null): void
    {
        $this->readOnlyGuard->assertWritable();

        $this->connection->statement('CREATE DATABASE '.$this->quoter->quote($name).$this->collationClause($collation));
    }

    public function renameBlockers(string $database): array
    {
        $counts = $this->connection->select(
            'SELECT
                (SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA = ?) AS views,
                (SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ?) AS routines,
                (SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA = ?) AS events,
                (SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ?) AS triggers',
            [$database, $database, $database, $database],
        )[0];

        $blockers = [];

        foreach (['views' => 'view', 'routines' => 'stored routine', 'events' => 'event', 'triggers' => 'trigger'] as $column => $label) {
            $count = (int) $counts[$column];

            if ($count > 0) {
                $blockers[] = $count.' '.$label.($count === 1 ? '' : 's');
            }
        }

        return $blockers;
    }

    public function renameDatabase(string $from, string $to): void
    {
        $this->readOnlyGuard->assertWritable();
        $this->assertNotSystemDatabase($from);

        $source = $this->database($from) ?? throw new DatabaseOperationException("The database \"{$from}\" does not exist.");

        if ($this->database($to) !== null) {
            throw new DatabaseOperationException("A database named \"{$to}\" already exists.");
        }

        $blockers = $this->renameBlockers($from);

        if ($blockers !== []) {
            throw new DatabaseOperationException(
                "\"{$from}\" cannot be renamed because it contains ".implode(', ', $blockers).'. Only databases with plain tables can be renamed.',
            );
        }

        $this->createDatabase($to, $source->collation);

        $tableMoves = array_map(
            fn (TableInfo $table): string => $this->qualified($from, $table->name).' TO '.$this->qualified($to, $table->name),
            $this->tables($from),
        );

        try {
            if ($tableMoves !== []) {
                // A single RENAME TABLE statement moves every table atomically.
                $this->connection->statement('RENAME TABLE '.implode(', ', $tableMoves));
            }
        } catch (DatabaseOperationException $exception) {
            $this->connection->statement('DROP DATABASE '.$this->quoter->quote($to));

            throw $exception;
        }

        $this->connection->statement('DROP DATABASE '.$this->quoter->quote($from));
    }

    public function dropDatabase(string $name): void
    {
        $this->readOnlyGuard->assertWritable();
        $this->assertNotSystemDatabase($name);

        $this->connection->statement('DROP DATABASE '.$this->quoter->quote($name));
    }

    public function busySessionCount(string $database): int
    {
        return (int) $this->connection->scalar(
            "SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE DB = ? AND COMMAND NOT IN ('Sleep', 'Daemon') AND ID <> CONNECTION_ID()",
            [$database],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function databaseRows(?string $name = null): array
    {
        return $this->connection->select(
            'SELECT s.SCHEMA_NAME, s.DEFAULT_CHARACTER_SET_NAME, s.DEFAULT_COLLATION_NAME,
                COUNT(t.TABLE_NAME) AS TABLE_COUNT,
                COALESCE(SUM(t.DATA_LENGTH + t.INDEX_LENGTH), 0) AS SIZE_BYTES
            FROM information_schema.SCHEMATA s
            LEFT JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = s.SCHEMA_NAME
            '.($name === null ? '' : 'WHERE s.SCHEMA_NAME = ?').'
            GROUP BY s.SCHEMA_NAME, s.DEFAULT_CHARACTER_SET_NAME, s.DEFAULT_COLLATION_NAME
            ORDER BY s.SCHEMA_NAME',
            $name === null ? [] : [$name],
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function toDatabaseInfo(array $row): DatabaseInfo
    {
        $name = (string) $row['SCHEMA_NAME'];

        return new DatabaseInfo(
            name: $name,
            characterSet: (string) $row['DEFAULT_CHARACTER_SET_NAME'],
            collation: (string) $row['DEFAULT_COLLATION_NAME'],
            tableCount: (int) $row['TABLE_COUNT'],
            sizeBytes: (int) $row['SIZE_BYTES'],
            isSystem: in_array($name, self::SYSTEM_DATABASES, true),
        );
    }

    /**
     * Build a COLLATE clause for a collation that exists on the server.
     *
     * @throws DatabaseOperationException
     */
    private function collationClause(?string $collation): string
    {
        if ($collation === null) {
            return '';
        }

        $exists = $this->connection->scalar('SELECT COUNT(*) FROM information_schema.COLLATIONS WHERE COLLATION_NAME = ?', [$collation]);

        if ((int) $exists === 0) {
            throw new DatabaseOperationException("The collation \"{$collation}\" is not available on this server.");
        }

        // Collation names are validated against the server's list and only contain [a-z0-9_].
        return ' COLLATE '.$collation;
    }

    private function qualified(string $database, string $table): string
    {
        return $this->quoter->quote($database).'.'.$this->quoter->quote($table);
    }

    /**
     * @throws DatabaseOperationException
     */
    private function assertNotSystemDatabase(string $name): void
    {
        if (in_array($name, self::SYSTEM_DATABASES, true)) {
            throw new DatabaseOperationException("\"{$name}\" is a MySQL system database and cannot be changed.");
        }
    }
}

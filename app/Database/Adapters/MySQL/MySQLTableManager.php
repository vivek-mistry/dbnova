<?php

namespace App\Database\Adapters\MySQL;

use App\Database\Contracts\TableManagerInterface;
use App\Database\DTOs\Maintenance\MaintenanceOperation;
use App\Database\DTOs\Maintenance\MaintenanceResult;
use App\Database\DTOs\Schema\AddColumn;
use App\Database\DTOs\Schema\ColumnDefinition;
use App\Database\DTOs\Schema\CreateTable;
use App\Database\DTOs\Schema\DdlPlan;
use App\Database\DTOs\Schema\DdlResult;
use App\Database\DTOs\Schema\ForeignKeyInfo;
use App\Database\DTOs\Schema\ModifyColumn;
use App\Database\DTOs\Schema\SchemaChange;
use App\Database\DTOs\TableRef;
use App\Database\Exceptions\DatabaseOperationException;
use App\Database\Exceptions\LockingDdlRequiresConfirmationException;
use App\Database\Support\IdentifierQuoter;
use App\Database\Support\ReadOnlyGuard;
use InvalidArgumentException;
use PDO;
use PDOException;

class MySQLTableManager implements TableManagerInterface
{
    /**
     * MySQL errors meaning the requested ALTER algorithm or lock level is not possible.
     *
     * @var list<int>
     */
    private const array UNSUPPORTED_ALGORITHM_CODES = [1845, 1846];

    /**
     * Servers before MySQL 8.0.12 (and MariaDB) do not know ALGORITHM=INSTANT.
     */
    private const int SYNTAX_ERROR_CODE = 1064;

    public function __construct(
        private MySQLConnection $connection,
        private MySQLSchemaManager $schemaManager,
        private IdentifierQuoter $quoter,
        private ReadOnlyGuard $readOnlyGuard,
    ) {}

    public function preview(TableRef $table, SchemaChange $change): DdlPlan
    {
        $statements = $this->statements($table, $change);
        $this->assertCollationsExist($change);

        $approximateRows = $change instanceof CreateTable ? null : $this->schemaManager->table($table->database, $table->table)?->approximateRows;
        $warnings = [];

        if ($change->isDestructive()) {
            $warnings[] = 'This permanently deletes data. It cannot be undone.';
        }

        if ($change->isAlter() && $approximateRows !== null && $approximateRows > $this->lockingThreshold()) {
            $warnings[] = 'This table has about '.number_format($approximateRows).' rows. DBNova asks MySQL to make the change online; '
                .'if MySQL can only do it by copying the table (blocking writes), you will be asked to confirm.';
        }

        return new DdlPlan($statements, $warnings, $approximateRows);
    }

    public function apply(TableRef $table, SchemaChange $change, bool $allowLocking = false): DdlResult
    {
        $this->readOnlyGuard->assertWritable();

        $statements = $this->statements($table, $change);
        $this->assertCollationsExist($change);

        if ($change->isAlter() && count($statements) === 1) {
            return $this->alterOnline($table, $statements[0], $allowLocking);
        }

        foreach ($statements as $statement) {
            $this->connection->statement($statement);
        }

        return new DdlResult($statements);
    }

    public function columnTypes(): array
    {
        return MySQLDdlBuilder::TYPES;
    }

    public function engines(): array
    {
        return MySQLDdlBuilder::ENGINES;
    }

    public function foreignKeys(TableRef $table): array
    {
        $rows = $this->connection->select(
            'SELECT k.CONSTRAINT_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_SCHEMA, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME,
                r.UPDATE_RULE, r.DELETE_RULE
            FROM information_schema.KEY_COLUMN_USAGE k
            JOIN information_schema.REFERENTIAL_CONSTRAINTS r
                ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
            WHERE k.TABLE_SCHEMA = ? AND k.TABLE_NAME = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL
            ORDER BY k.CONSTRAINT_NAME, k.ORDINAL_POSITION',
            [$table->database, $table->table],
        );

        $grouped = [];

        foreach ($rows as $row) {
            $name = (string) $row['CONSTRAINT_NAME'];
            $grouped[$name] ??= [
                'columns' => [],
                'referencedColumns' => [],
                'referencedDatabase' => (string) $row['REFERENCED_TABLE_SCHEMA'],
                'referencedTable' => (string) $row['REFERENCED_TABLE_NAME'],
                'onUpdate' => (string) $row['UPDATE_RULE'],
                'onDelete' => (string) $row['DELETE_RULE'],
            ];
            $grouped[$name]['columns'][] = (string) $row['COLUMN_NAME'];
            $grouped[$name]['referencedColumns'][] = (string) $row['REFERENCED_COLUMN_NAME'];
        }

        return array_map(
            fn (string $name, array $foreignKey): ForeignKeyInfo => new ForeignKeyInfo(
                $name,
                $foreignKey['columns'],
                $foreignKey['referencedDatabase'],
                $foreignKey['referencedTable'],
                $foreignKey['referencedColumns'],
                $foreignKey['onUpdate'],
                $foreignKey['onDelete'],
            ),
            array_keys($grouped),
            array_values($grouped),
        );
    }

    public function maintain(string $database, array $tables, MaintenanceOperation $operation): array
    {
        if ($operation->changesServer()) {
            $this->readOnlyGuard->assertWritable();
        }

        if ($tables === []) {
            return [];
        }

        // Only names that are base tables in the catalog reach the statement (views cannot be optimized or repaired).
        $known = [];

        foreach ($this->schemaManager->tables($database) as $info) {
            if ($info->type === 'table') {
                $known[$info->name] = true;
            }
        }

        $quoted = [];

        foreach (array_values(array_unique($tables)) as $table) {
            if (! isset($known[$table])) {
                throw new DatabaseOperationException("The table \"{$table}\" does not exist in \"{$database}\".");
            }

            $quoted[] = $this->quoter->quote($database).'.'.$this->quoter->quote($table);
        }

        // OPTIMIZE/ANALYZE/REPAIR/CHECK return result rows; CHECK TABLE cannot be prepared, so run it as a plain query.
        try {
            $statement = $this->connection->pdo()->query($operation->keyword().' TABLE '.implode(', ', $quoted));

            /** @var list<array<string, mixed>> $rows */
            $rows = $statement === false ? [] : $statement->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $exception) {
            throw DatabaseOperationException::fromPdoException($exception);
        }

        return array_map(fn (array $row): MaintenanceResult => new MaintenanceResult(
            table: (string) ($row['Table'] ?? ''),
            operation: (string) ($row['Op'] ?? $operation->value),
            type: strtolower((string) ($row['Msg_type'] ?? '')),
            message: (string) ($row['Msg_text'] ?? ''),
        ), $rows);
    }

    /**
     * Try INSTANT, then INPLACE without locks, and only then a copying ALTER,
     * which on a big table needs the user's confirmation. MySQL rejects an
     * unsupported algorithm before doing any work, so each attempt is safe.
     */
    private function alterOnline(TableRef $table, string $statement, bool $allowLocking): DdlResult
    {
        $builder = $this->builder();

        try {
            $this->connection->statement($builder->withAlgorithm($statement, 'ALGORITHM=INSTANT'));

            return new DdlResult([$statement], 'INSTANT');
        } catch (DatabaseOperationException $exception) {
            if (! in_array($exception->getCode(), [...self::UNSUPPORTED_ALGORITHM_CODES, self::SYNTAX_ERROR_CODE], true)) {
                throw $exception;
            }
        }

        try {
            $this->connection->statement($builder->withAlgorithm($statement, 'ALGORITHM=INPLACE, LOCK=NONE'));

            return new DdlResult([$statement], 'INPLACE');
        } catch (DatabaseOperationException $exception) {
            if (! in_array($exception->getCode(), self::UNSUPPORTED_ALGORITHM_CODES, true)) {
                throw $exception;
            }
        }

        $approximateRows = $this->schemaManager->table($table->database, $table->table)->approximateRows ?? 0;

        if ($approximateRows > $this->lockingThreshold() && ! $allowLocking) {
            throw new LockingDdlRequiresConfirmationException($approximateRows);
        }

        $this->connection->statement($statement);

        return new DdlResult([$statement], 'COPY');
    }

    /**
     * @return list<string>
     *
     * @throws DatabaseOperationException
     */
    private function statements(TableRef $table, SchemaChange $change): array
    {
        try {
            return $this->builder()->statements($table, $change);
        } catch (InvalidArgumentException $exception) {
            throw new DatabaseOperationException($exception->getMessage(), 0, $exception);
        }
    }

    /**
     * @throws DatabaseOperationException
     */
    private function assertCollationsExist(SchemaChange $change): void
    {
        $columns = match (true) {
            $change instanceof AddColumn => [$change->column],
            $change instanceof ModifyColumn => [$change->definition],
            $change instanceof CreateTable => $change->columns,
            default => [],
        };

        $collations = array_filter([
            ...array_map(fn (ColumnDefinition $column): ?string => $column->collation, $columns),
            $change instanceof CreateTable ? $change->collation : null,
        ], fn (?string $collation): bool => $collation !== null && $collation !== '');

        foreach (array_unique($collations) as $collation) {
            $exists = $this->connection->scalar('SELECT COUNT(*) FROM information_schema.COLLATIONS WHERE COLLATION_NAME = ?', [$collation]);

            if ((int) $exists === 0) {
                throw new DatabaseOperationException("The collation \"{$collation}\" is not available on this server.");
            }
        }
    }

    private function builder(): MySQLDdlBuilder
    {
        return new MySQLDdlBuilder($this->quoter, fn (string $value): string => $this->connection->quote($value));
    }

    private function lockingThreshold(): int
    {
        return config()->integer('dbnova.limits.locking_ddl_confirm_rows');
    }
}

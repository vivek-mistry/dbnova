<?php

namespace App\Database\Adapters\MySQL;

use App\Database\DTOs\Schema\AddColumn;
use App\Database\DTOs\Schema\AddForeignKey;
use App\Database\DTOs\Schema\AddIndex;
use App\Database\DTOs\Schema\ColumnDefaultKind;
use App\Database\DTOs\Schema\ColumnDefinition;
use App\Database\DTOs\Schema\CopyTable;
use App\Database\DTOs\Schema\CreateTable;
use App\Database\DTOs\Schema\DropColumn;
use App\Database\DTOs\Schema\DropForeignKey;
use App\Database\DTOs\Schema\DropIndex;
use App\Database\DTOs\Schema\DropTable;
use App\Database\DTOs\Schema\IndexType;
use App\Database\DTOs\Schema\ModifyColumn;
use App\Database\DTOs\Schema\RenameTable;
use App\Database\DTOs\Schema\SchemaChange;
use App\Database\DTOs\Schema\TruncateTable;
use App\Database\DTOs\TableRef;
use App\Database\Support\IdentifierQuoter;
use Closure;
use InvalidArgumentException;

/**
 * Turns schema changes into MySQL DDL. It validates types, lengths and
 * options itself, quotes every identifier and passes every literal (defaults,
 * ENUM values, comments) through the connection's quoter.
 */
class MySQLDdlBuilder
{
    /** @var list<string> */
    public const array INTEGER_TYPES = ['tinyint', 'smallint', 'mediumint', 'int', 'bigint'];

    /** @var list<string> */
    public const array STRING_TYPES = ['char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext', 'enum', 'set'];

    /** @var list<string> */
    private const array NO_LITERAL_DEFAULT_TYPES = [
        'tinytext', 'text', 'mediumtext', 'longtext', 'tinyblob', 'blob', 'mediumblob', 'longblob', 'json',
        'geometry', 'point', 'linestring', 'polygon', 'multipoint', 'multilinestring', 'multipolygon', 'geometrycollection',
    ];

    /** @var list<string> */
    public const array TYPES = [
        'tinyint', 'smallint', 'mediumint', 'int', 'bigint', 'decimal', 'float', 'double', 'bit',
        'date', 'datetime', 'timestamp', 'time', 'year',
        'char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext',
        'binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob',
        'enum', 'set', 'json',
        'geometry', 'point', 'linestring', 'polygon', 'multipoint', 'multilinestring', 'multipolygon', 'geometrycollection',
    ];

    /** @var list<string> */
    public const array ENGINES = ['InnoDB', 'MyISAM', 'MEMORY', 'ARCHIVE', 'CSV'];

    /**
     * @param  Closure(string): string  $quoteLiteral  Quotes a string literal for the target server.
     */
    public function __construct(
        private IdentifierQuoter $quoter,
        private Closure $quoteLiteral,
    ) {}

    /**
     * The statements that apply a change, in order.
     *
     * @return list<string>
     *
     * @throws InvalidArgumentException when the change is not valid.
     */
    public function statements(TableRef $table, SchemaChange $change): array
    {
        $qualified = $this->qualified($table->database, $table->table);
        $alter = 'ALTER TABLE '.$qualified.' ';

        return match (true) {
            $change instanceof AddColumn => [$alter.'ADD COLUMN '.$this->column($change->column).$this->position($change->after, $change->first)],
            $change instanceof ModifyColumn => [$alter.'CHANGE COLUMN '.$this->quoter->quote($change->column).' '.$this->column($change->definition).$this->position($change->after, $change->first)],
            $change instanceof DropColumn => [$alter.'DROP COLUMN '.$this->quoter->quote($change->column)],
            $change instanceof AddIndex => [$alter.'ADD '.$this->index($change)],
            $change instanceof DropIndex => [$alter.(strtoupper($change->name) === 'PRIMARY' ? 'DROP PRIMARY KEY' : 'DROP INDEX '.$this->quoter->quote($change->name))],
            $change instanceof AddForeignKey => [$alter.'ADD '.$this->foreignKey($table, $change)],
            $change instanceof DropForeignKey => [$alter.'DROP FOREIGN KEY '.$this->quoter->quote($change->name)],
            $change instanceof RenameTable => ['RENAME TABLE '.$qualified.' TO '.$this->qualified($table->database, $change->newName)],
            $change instanceof CopyTable => $this->copyTable($table, $change),
            $change instanceof TruncateTable => ['TRUNCATE TABLE '.$qualified],
            $change instanceof DropTable => ['DROP TABLE '.$qualified],
            $change instanceof CreateTable => [$this->createTable($qualified, $change)],
            default => throw new InvalidArgumentException('Unsupported schema change.'),
        };
    }

    /**
     * A column definition, e.g. "`price` decimal(10,2) UNSIGNED NOT NULL DEFAULT '0.00'".
     */
    public function column(ColumnDefinition $column): string
    {
        $type = strtolower($column->type);

        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException("Unsupported column type \"{$column->type}\".");
        }

        $isTimestampType = in_array($type, ['datetime', 'timestamp'], true);
        $parts = [$this->quoter->quote($column->name), $type.$this->typeArguments($type, $column)];

        if ($column->unsigned) {
            if (! in_array($type, [...self::INTEGER_TYPES, 'decimal', 'float', 'double'], true)) {
                throw new InvalidArgumentException("Only numeric columns can be UNSIGNED, not {$type}.");
            }

            $parts[] = 'UNSIGNED';
        }

        if ($column->collation !== null && $column->collation !== '') {
            if (! in_array($type, self::STRING_TYPES, true)) {
                throw new InvalidArgumentException("Only text columns can have a collation, not {$type}.");
            }

            if (preg_match('/^[A-Za-z0-9_]+$/', $column->collation) !== 1) {
                throw new InvalidArgumentException('Collation names may only contain letters, digits and underscores.');
            }

            $parts[] = 'COLLATE '.$column->collation;
        }

        $parts[] = $column->nullable ? 'NULL' : 'NOT NULL';

        $fractionalSeconds = $column->length !== null && $column->length !== '' ? '('.$column->length.')' : '';

        $defaultClause = $this->defaultClause($column, $type, $isTimestampType, $fractionalSeconds);

        if ($defaultClause !== null) {
            $parts[] = $defaultClause;
        }

        if ($column->onUpdateCurrentTimestamp) {
            if (! $isTimestampType) {
                throw new InvalidArgumentException('Only DATETIME and TIMESTAMP columns can update to CURRENT_TIMESTAMP.');
            }

            $parts[] = 'ON UPDATE CURRENT_TIMESTAMP'.$fractionalSeconds;
        }

        if ($column->autoIncrement) {
            if (! in_array($type, self::INTEGER_TYPES, true)) {
                throw new InvalidArgumentException('Only integer columns can AUTO_INCREMENT.');
            }

            if ($column->default->kind !== ColumnDefaultKind::None) {
                throw new InvalidArgumentException('AUTO_INCREMENT columns cannot have a default.');
            }

            $parts[] = 'AUTO_INCREMENT';
        }

        if ($column->comment !== '') {
            if (mb_strlen($column->comment) > 1024) {
                throw new InvalidArgumentException('Column comments cannot be longer than 1024 characters.');
            }

            $parts[] = 'COMMENT '.($this->quoteLiteral)($column->comment);
        }

        return implode(' ', $parts);
    }

    /**
     * Append an ALTER TABLE algorithm clause, e.g. ", ALGORITHM=INSTANT".
     */
    public function withAlgorithm(string $alterStatement, string $clause): string
    {
        return $alterStatement.', '.$clause;
    }

    private function defaultClause(ColumnDefinition $column, string $type, bool $isTimestampType, string $fractionalSeconds): ?string
    {
        switch ($column->default->kind) {
            case ColumnDefaultKind::None:
                return null;

            case ColumnDefaultKind::Null:
                if (! $column->nullable) {
                    throw new InvalidArgumentException("\"{$column->name}\" is NOT NULL, so its default cannot be NULL.");
                }

                return 'DEFAULT NULL';

            case ColumnDefaultKind::CurrentTimestamp:
                if (! $isTimestampType) {
                    throw new InvalidArgumentException('Only DATETIME and TIMESTAMP columns can default to CURRENT_TIMESTAMP.');
                }

                return 'DEFAULT CURRENT_TIMESTAMP'.$fractionalSeconds;

            case ColumnDefaultKind::Value:
                if (in_array($type, self::NO_LITERAL_DEFAULT_TYPES, true)) {
                    throw new InvalidArgumentException("{$type} columns cannot have a default value.");
                }

                return 'DEFAULT '.($this->quoteLiteral)((string) $column->default->value);
        }
    }

    private function typeArguments(string $type, ColumnDefinition $column): string
    {
        $length = $column->length === null ? '' : trim($column->length);

        if (in_array($type, ['enum', 'set'], true)) {
            if ($column->values === []) {
                throw new InvalidArgumentException(strtoupper($type).' columns need at least one allowed value.');
            }

            return '('.implode(',', array_map(fn (string $value): string => ($this->quoteLiteral)($value), $column->values)).')';
        }

        if ($length === '') {
            if (in_array($type, ['varchar', 'varbinary'], true)) {
                throw new InvalidArgumentException(strtoupper($type).' columns need a length.');
            }

            return '';
        }

        $valid = match (true) {
            in_array($type, ['varchar', 'varbinary'], true) => $this->isIntegerBetween($length, 1, 65535),
            in_array($type, ['char', 'binary'], true) => $this->isIntegerBetween($length, 0, 255),
            $type === 'bit' => $this->isIntegerBetween($length, 1, 64),
            in_array($type, ['datetime', 'timestamp', 'time'], true) => $this->isIntegerBetween($length, 0, 6),
            $type === 'decimal' => $this->isValidPrecision($length, 65, 30),
            in_array($type, ['float', 'double'], true) => $this->isValidPrecision($length, 255, 30, requireScale: true),
            default => throw new InvalidArgumentException(in_array($type, self::INTEGER_TYPES, true)
                ? 'Integer display widths are deprecated in MySQL 8. Leave the length empty.'
                : strtoupper($type).' columns do not take a length.'),
        };

        if (! $valid) {
            throw new InvalidArgumentException("\"{$length}\" is not a valid length for ".strtoupper($type).'.');
        }

        return '('.$length.')';
    }

    private function isIntegerBetween(string $value, int $min, int $max): bool
    {
        return preg_match('/^\d{1,5}$/', $value) === 1 && (int) $value >= $min && (int) $value <= $max;
    }

    private function isValidPrecision(string $value, int $maxDigits, int $maxScale, bool $requireScale = false): bool
    {
        if (preg_match('/^(\d{1,3})(?:,(\d{1,2}))?$/', $value, $matches) !== 1) {
            return false;
        }

        $digits = (int) $matches[1];
        $scale = isset($matches[2]) ? (int) $matches[2] : null;

        if ($requireScale && $scale === null) {
            return false;
        }

        return $digits >= 1 && $digits <= $maxDigits && ($scale === null || ($scale <= $maxScale && $scale <= $digits));
    }

    private function position(?string $after, bool $first): string
    {
        if ($first) {
            return ' FIRST';
        }

        return $after === null || $after === '' ? '' : ' AFTER '.$this->quoter->quote($after);
    }

    private function index(AddIndex $change): string
    {
        $index = $change->index;

        if ($index->columns === []) {
            throw new InvalidArgumentException('An index needs at least one column.');
        }

        if (count(array_unique($index->columns)) !== count($index->columns)) {
            throw new InvalidArgumentException('An index cannot list the same column twice.');
        }

        $columns = '('.implode(', ', array_map($this->quoter->quote(...), $index->columns)).')';

        if ($index->type === IndexType::Primary) {
            return 'PRIMARY KEY '.$columns;
        }

        $keyword = match ($index->type) {
            IndexType::Unique => 'UNIQUE INDEX',
            IndexType::Fulltext => 'FULLTEXT INDEX',
            default => 'INDEX',
        };

        $name = $index->name === null || $index->name === '' ? '' : ' '.$this->quoter->quote($index->name);

        return $keyword.$name.' '.$columns;
    }

    private function foreignKey(TableRef $table, AddForeignKey $change): string
    {
        $foreignKey = $change->foreignKey;

        if ($foreignKey->columns === [] || count($foreignKey->columns) !== count($foreignKey->referencedColumns)) {
            throw new InvalidArgumentException('A foreign key needs the same number of columns on both sides.');
        }

        $constraint = $foreignKey->name === null || $foreignKey->name === '' ? '' : 'CONSTRAINT '.$this->quoter->quote($foreignKey->name).' ';

        return $constraint
            .'FOREIGN KEY ('.implode(', ', array_map($this->quoter->quote(...), $foreignKey->columns)).')'
            .' REFERENCES '.$this->qualified($table->database, $foreignKey->referencedTable)
            .' ('.implode(', ', array_map($this->quoter->quote(...), $foreignKey->referencedColumns)).')'
            .' ON DELETE '.$foreignKey->onDelete->value
            .' ON UPDATE '.$foreignKey->onUpdate->value;
    }

    /**
     * @return list<string>
     */
    private function copyTable(TableRef $table, CopyTable $change): array
    {
        $source = $this->qualified($table->database, $table->table);
        $target = $this->qualified($table->database, $change->newName);
        $statements = ['CREATE TABLE '.$target.' LIKE '.$source];

        if ($change->withData) {
            $statements[] = 'INSERT INTO '.$target.' SELECT * FROM '.$source;
        }

        return $statements;
    }

    private function createTable(string $qualified, CreateTable $change): string
    {
        if ($change->columns === []) {
            throw new InvalidArgumentException('A table needs at least one column.');
        }

        $names = array_map(fn (ColumnDefinition $column): string => $column->name, $change->columns);

        if (count(array_unique(array_map('strtolower', $names))) !== count($names)) {
            throw new InvalidArgumentException('Column names must be unique.');
        }

        $definitions = array_map($this->column(...), $change->columns);

        if ($change->primaryKey !== []) {
            foreach ($change->primaryKey as $keyColumn) {
                if (! in_array($keyColumn, $names, true)) {
                    throw new InvalidArgumentException("The primary key column \"{$keyColumn}\" is not in the table.");
                }
            }

            $definitions[] = 'PRIMARY KEY ('.implode(', ', array_map($this->quoter->quote(...), $change->primaryKey)).')';
        }

        $sql = 'CREATE TABLE '.$qualified." (\n  ".implode(",\n  ", $definitions)."\n)";

        if ($change->engine !== null && $change->engine !== '') {
            if (! in_array($change->engine, self::ENGINES, true)) {
                throw new InvalidArgumentException("Unsupported storage engine \"{$change->engine}\".");
            }

            $sql .= ' ENGINE='.$change->engine;
        }

        if ($change->collation !== null && $change->collation !== '') {
            if (preg_match('/^[A-Za-z0-9_]+$/', $change->collation) !== 1) {
                throw new InvalidArgumentException('Collation names may only contain letters, digits and underscores.');
            }

            $sql .= ' COLLATE='.$change->collation;
        }

        if ($change->comment !== '') {
            $sql .= ' COMMENT='.($this->quoteLiteral)($change->comment);
        }

        return $sql;
    }

    private function qualified(string $database, string $table): string
    {
        return $this->quoter->quote($database).'.'.$this->quoter->quote($table);
    }
}

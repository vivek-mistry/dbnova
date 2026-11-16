<?php

use App\Database\Adapters\MySQL\MySQLDdlBuilder;
use App\Database\DTOs\Schema\AddColumn;
use App\Database\DTOs\Schema\AddForeignKey;
use App\Database\DTOs\Schema\AddIndex;
use App\Database\DTOs\Schema\ColumnDefault;
use App\Database\DTOs\Schema\ColumnDefaultKind;
use App\Database\DTOs\Schema\ColumnDefinition;
use App\Database\DTOs\Schema\CopyTable;
use App\Database\DTOs\Schema\CreateTable;
use App\Database\DTOs\Schema\DropColumn;
use App\Database\DTOs\Schema\DropForeignKey;
use App\Database\DTOs\Schema\DropIndex;
use App\Database\DTOs\Schema\DropTable;
use App\Database\DTOs\Schema\ForeignKeyAction;
use App\Database\DTOs\Schema\ForeignKeyDefinition;
use App\Database\DTOs\Schema\IndexDefinition;
use App\Database\DTOs\Schema\IndexType;
use App\Database\DTOs\Schema\ModifyColumn;
use App\Database\DTOs\Schema\RenameTable;
use App\Database\DTOs\Schema\TruncateTable;
use App\Database\DTOs\TableRef;
use App\Database\Support\IdentifierQuoter;

/**
 * Literals are quoted by doubling single quotes, like the MySQL driver does.
 */
function ddlBuilder(): MySQLDdlBuilder
{
    return new MySQLDdlBuilder(new IdentifierQuoter, fn (string $value): string => "'".str_replace("'", "''", $value)."'");
}

function ddl(object $change): array
{
    return ddlBuilder()->statements(new TableRef('shop', 'orders'), $change);
}

function columnSql(ColumnDefinition $column): string
{
    return ddlBuilder()->column($column);
}

describe('column definitions', function () {
    test('valid columns become DDL', function (ColumnDefinition $column, string $sql) {
        expect(columnSql($column))->toBe($sql);
    })->with([
        'auto-increment id' => [
            new ColumnDefinition('id', 'bigint', unsigned: true, nullable: false, autoIncrement: true),
            '`id` bigint UNSIGNED NOT NULL AUTO_INCREMENT',
        ],
        'varchar with default and collation' => [
            new ColumnDefinition('status', 'varchar', '20', nullable: false, default: new ColumnDefault(ColumnDefaultKind::Value, 'new'), collation: 'utf8mb4_bin'),
            "`status` varchar(20) COLLATE utf8mb4_bin NOT NULL DEFAULT 'new'",
        ],
        'decimal precision' => [
            new ColumnDefinition('amount', 'decimal', '10,2', unsigned: true, default: new ColumnDefault(ColumnDefaultKind::Value, '0.00')),
            "`amount` decimal(10,2) UNSIGNED NULL DEFAULT '0.00'",
        ],
        'nullable with DEFAULT NULL' => [
            new ColumnDefinition('notes', 'text', default: new ColumnDefault(ColumnDefaultKind::Null)),
            '`notes` text NULL DEFAULT NULL',
        ],
        'timestamps with fractional seconds' => [
            new ColumnDefinition('updated_at', 'datetime', '3', nullable: false, default: new ColumnDefault(ColumnDefaultKind::CurrentTimestamp), onUpdateCurrentTimestamp: true),
            '`updated_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)',
        ],
        'enum values and comment are quoted literals' => [
            new ColumnDefinition('mood', 'enum', values: ['happy', "it's fine"], comment: "Customer's mood"),
            "`mood` enum('happy','it''s fine') NULL COMMENT 'Customer''s mood'",
        ],
        'type names are case-insensitive' => [
            new ColumnDefinition('code', 'CHAR', '2'),
            '`code` char(2) NULL',
        ],
    ]);

    test('invalid columns are rejected with a reason', function (ColumnDefinition $column, string $message) {
        expect(fn () => columnSql($column))->toThrow(InvalidArgumentException::class, $message);
    })->with([
        'unknown type' => [new ColumnDefinition('a', 'varchar2', '10'), 'Unsupported column type "varchar2".'],
        'type injection' => [new ColumnDefinition('a', 'int; DROP TABLE x'), 'Unsupported column type'],
        'varchar needs a length' => [new ColumnDefinition('a', 'varchar'), 'VARCHAR columns need a length.'],
        'length injection' => [new ColumnDefinition('a', 'varchar', '10) NOT NULL, DROP x; --'), 'is not a valid length for VARCHAR'],
        'decimal scale above precision' => [new ColumnDefinition('a', 'decimal', '5,6'), 'is not a valid length for DECIMAL'],
        'integer display width' => [new ColumnDefinition('a', 'int', '11'), 'Integer display widths are deprecated'],
        'text takes no length' => [new ColumnDefinition('a', 'text', '100'), 'TEXT columns do not take a length.'],
        'unsigned text' => [new ColumnDefinition('a', 'varchar', '10', unsigned: true), 'Only numeric columns can be UNSIGNED'],
        'collation on a number' => [new ColumnDefinition('a', 'int', collation: 'utf8mb4_bin'), 'Only text columns can have a collation'],
        'collation injection' => [new ColumnDefinition('a', 'varchar', '10', collation: 'utf8mb4_bin; DROP'), 'Collation names may only contain'],
        'NULL default on NOT NULL' => [new ColumnDefinition('a', 'int', nullable: false, default: new ColumnDefault(ColumnDefaultKind::Null)), 'its default cannot be NULL'],
        'CURRENT_TIMESTAMP on a date' => [new ColumnDefinition('a', 'date', default: new ColumnDefault(ColumnDefaultKind::CurrentTimestamp)), 'Only DATETIME and TIMESTAMP columns can default'],
        'literal default on TEXT' => [new ColumnDefinition('a', 'text', default: new ColumnDefault(ColumnDefaultKind::Value, 'x')), 'text columns cannot have a default value.'],
        'auto increment on varchar' => [new ColumnDefinition('a', 'varchar', '10', autoIncrement: true), 'Only integer columns can AUTO_INCREMENT.'],
        'auto increment with default' => [new ColumnDefinition('a', 'int', autoIncrement: true, default: new ColumnDefault(ColumnDefaultKind::Value, '1')), 'AUTO_INCREMENT columns cannot have a default.'],
        'enum without values' => [new ColumnDefinition('a', 'enum'), 'ENUM columns need at least one allowed value.'],
    ]);
});

describe('changes', function () {
    test('each change produces its statement', function (object $change, array $statements) {
        expect(ddl($change))->toBe($statements);
    })->with([
        'add column after another' => [
            new AddColumn(new ColumnDefinition('paid', 'tinyint', unsigned: true, nullable: false, default: new ColumnDefault(ColumnDefaultKind::Value, '0')), after: 'status'),
            ["ALTER TABLE `shop`.`orders` ADD COLUMN `paid` tinyint UNSIGNED NOT NULL DEFAULT '0' AFTER `status`"],
        ],
        'modify (rename) a column first' => [
            new ModifyColumn('notes', new ColumnDefinition('remarks', 'text'), first: true),
            ['ALTER TABLE `shop`.`orders` CHANGE COLUMN `notes` `remarks` text NULL FIRST'],
        ],
        'drop column' => [new DropColumn('notes'), ['ALTER TABLE `shop`.`orders` DROP COLUMN `notes`']],
        'add named unique index' => [
            new AddIndex(new IndexDefinition(IndexType::Unique, ['email', 'tenant_id'], 'orders_email_unique')),
            ['ALTER TABLE `shop`.`orders` ADD UNIQUE INDEX `orders_email_unique` (`email`, `tenant_id`)'],
        ],
        'add primary key' => [
            new AddIndex(new IndexDefinition(IndexType::Primary, ['id'])),
            ['ALTER TABLE `shop`.`orders` ADD PRIMARY KEY (`id`)'],
        ],
        'add unnamed fulltext index' => [
            new AddIndex(new IndexDefinition(IndexType::Fulltext, ['notes'])),
            ['ALTER TABLE `shop`.`orders` ADD FULLTEXT INDEX (`notes`)'],
        ],
        'drop index' => [new DropIndex('orders_email_unique'), ['ALTER TABLE `shop`.`orders` DROP INDEX `orders_email_unique`']],
        'drop primary key' => [new DropIndex('PRIMARY'), ['ALTER TABLE `shop`.`orders` DROP PRIMARY KEY']],
        'add foreign key' => [
            new AddForeignKey(new ForeignKeyDefinition(['customer_id'], 'customers', ['id'], ForeignKeyAction::Cascade, ForeignKeyAction::SetNull, 'orders_customer_fk')),
            ['ALTER TABLE `shop`.`orders` ADD CONSTRAINT `orders_customer_fk` FOREIGN KEY (`customer_id`) REFERENCES `shop`.`customers` (`id`) ON DELETE CASCADE ON UPDATE SET NULL'],
        ],
        'drop foreign key' => [new DropForeignKey('orders_customer_fk'), ['ALTER TABLE `shop`.`orders` DROP FOREIGN KEY `orders_customer_fk`']],
        'rename table' => [new RenameTable('purchases'), ['RENAME TABLE `shop`.`orders` TO `shop`.`purchases`']],
        'copy structure only' => [new CopyTable('orders_copy'), ['CREATE TABLE `shop`.`orders_copy` LIKE `shop`.`orders`']],
        'copy with rows' => [new CopyTable('orders_copy', withData: true), [
            'CREATE TABLE `shop`.`orders_copy` LIKE `shop`.`orders`',
            'INSERT INTO `shop`.`orders_copy` SELECT * FROM `shop`.`orders`',
        ]],
        'truncate' => [new TruncateTable, ['TRUNCATE TABLE `shop`.`orders`']],
        'drop table' => [new DropTable, ['DROP TABLE `shop`.`orders`']],
    ]);

    test('a new table lists its columns, primary key and options', function () {
        $statements = ddl(new CreateTable(
            [
                new ColumnDefinition('id', 'bigint', unsigned: true, nullable: false, autoIncrement: true),
                new ColumnDefinition('name', 'varchar', '100', nullable: false),
            ],
            ['id'],
            'InnoDB',
            'utf8mb4_unicode_ci',
            "Customer's orders",
        ));

        expect($statements)->toBe([
            "CREATE TABLE `shop`.`orders` (\n"
            ."  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,\n"
            ."  `name` varchar(100) NOT NULL,\n"
            ."  PRIMARY KEY (`id`)\n"
            .") ENGINE=InnoDB COLLATE=utf8mb4_unicode_ci COMMENT='Customer''s orders'",
        ]);
    });

    test('invalid changes are rejected', function (object $change, string $message) {
        expect(fn () => ddl($change))->toThrow(InvalidArgumentException::class, $message);
    })->with([
        'index without columns' => [new AddIndex(new IndexDefinition(IndexType::Index, [])), 'An index needs at least one column.'],
        'index repeats a column' => [new AddIndex(new IndexDefinition(IndexType::Index, ['a', 'a'])), 'An index cannot list the same column twice.'],
        'foreign key column count mismatch' => [new AddForeignKey(new ForeignKeyDefinition(['a', 'b'], 'customers', ['id'])), 'same number of columns on both sides'],
        'table without columns' => [new CreateTable([]), 'A table needs at least one column.'],
        'duplicate column names' => [new CreateTable([new ColumnDefinition('a', 'int'), new ColumnDefinition('A', 'int')]), 'Column names must be unique.'],
        'primary key on a missing column' => [new CreateTable([new ColumnDefinition('a', 'int')], ['b']), 'The primary key column "b" is not in the table.'],
        'unknown engine' => [new CreateTable([new ColumnDefinition('a', 'int')], engine: 'InnoDB; DROP'), 'Unsupported storage engine'],
    ]);

    test('identifiers containing backticks are escaped everywhere', function () {
        expect(ddl(new DropColumn('a`; DROP TABLE x; --')))->toBe(['ALTER TABLE `shop`.`orders` DROP COLUMN `a``; DROP TABLE x; --`']);
        expect(ddl(new RenameTable('x` TO `evil')))->toBe(['RENAME TABLE `shop`.`orders` TO `shop`.`x`` TO ``evil`']);
    });
});

test('an algorithm clause is appended to ALTER statements', function () {
    expect(ddlBuilder()->withAlgorithm('ALTER TABLE `t` ADD INDEX (`a`)', 'ALGORITHM=INPLACE, LOCK=NONE'))
        ->toBe('ALTER TABLE `t` ADD INDEX (`a`), ALGORITHM=INPLACE, LOCK=NONE');
});

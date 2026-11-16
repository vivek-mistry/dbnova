<?php

use App\Database\Adapters\MySQL\MySQLConnection;
use App\Database\Contracts\DatabaseAdapterInterface;
use App\Database\DTOs\ColumnInfo;
use App\Database\DTOs\Maintenance\MaintenanceOperation;
use App\Database\DTOs\Maintenance\MaintenanceResult;
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
use App\Database\DTOs\Schema\DropTable;
use App\Database\DTOs\Schema\ForeignKeyAction;
use App\Database\DTOs\Schema\ForeignKeyDefinition;
use App\Database\DTOs\Schema\IndexDefinition;
use App\Database\DTOs\Schema\IndexType;
use App\Database\DTOs\Schema\ModifyColumn;
use App\Database\DTOs\Schema\RenameTable;
use App\Database\DTOs\Schema\TruncateTable;
use App\Database\DTOs\TableRef;
use App\Database\Exceptions\DatabaseOperationException;
use App\Database\Exceptions\LockingDdlRequiresConfirmationException;
use App\Database\Exceptions\ReadOnlyModeException;
use App\Models\User;

/**
 * Runs against a real MySQL server when DBNOVA_TEST_DB_USERNAME is set.
 */
beforeEach(function () {
    $credentials = mySqlTestCredentials();

    if ($credentials === null) {
        $this->markTestSkipped('Set DBNOVA_TEST_DB_USERNAME in .env to run MySQL integration tests.');
    }

    actingAsMySqlUser(User::factory()->create(['username' => $credentials['username']]), $credentials['password']);

    $adapter = app(DatabaseAdapterInterface::class);
    $this->connection = app(MySQLConnection::class);
    $this->tables = $adapter->tables();
    $this->browser = $adapter->browser();
    $this->database = 'dbnova_test_'.bin2hex(random_bytes(4));
    $this->connection->statement("CREATE DATABASE `{$this->database}`");

    $this->createCustomers = fn () => $this->tables->apply(new TableRef($this->database, 'customers'), new CreateTable(
        [
            new ColumnDefinition('id', 'bigint', unsigned: true, nullable: false, autoIncrement: true),
            new ColumnDefinition('email', 'varchar', '150', nullable: false, collation: 'utf8mb4_bin'),
            new ColumnDefinition('note', 'text'),
        ],
        ['id'],
        'InnoDB',
    ));
})->group('mysql');

afterEach(function () {
    if (isset($this->connection, $this->database)) {
        $this->connection->statement("DROP DATABASE IF EXISTS `{$this->database}`");
    }
});

/**
 * @return array<string, string>
 */
function columnTypes(array $columns): array
{
    return collect($columns)->mapWithKeys(fn (ColumnInfo $column): array => [$column->name => $column->columnType])->all();
}

test('a created table has the requested columns and key', function () {
    ($this->createCustomers)();
    $customers = new TableRef($this->database, 'customers');

    expect(columnTypes($this->browser->columns($customers)))->toBe([
        'id' => 'bigint unsigned',
        'email' => 'varchar(150)',
        'note' => 'text',
    ]);
    expect($this->browser->keyColumns($customers))->toBe(['id']);
    expect($this->browser->columns($customers)[1]->collation)->toBe('utf8mb4_bin');
});

test('columns are added online, changed and dropped', function () {
    ($this->createCustomers)();
    $customers = new TableRef($this->database, 'customers');

    $added = $this->tables->apply($customers, new AddColumn(
        new ColumnDefinition('active', 'tinyint', unsigned: true, nullable: false, default: new ColumnDefault(ColumnDefaultKind::Value, '1')),
        after: 'email',
    ));
    $changed = $this->tables->apply($customers, new ModifyColumn(
        'email',
        new ColumnDefinition('email_address', 'varchar', '200', nullable: false, collation: 'utf8mb4_bin'),
    ));
    $this->tables->apply($customers, new DropColumn('note'));

    expect($added->algorithm)->toBe('INSTANT');
    expect($changed->algorithm)->toBeIn(['INSTANT', 'INPLACE']);
    expect(columnTypes($this->browser->columns($customers)))->toBe([
        'id' => 'bigint unsigned',
        'email_address' => 'varchar(200)',
        'active' => 'tinyint unsigned',
    ]);
    expect($this->browser->columns($customers)[1]->collation)->toBe('utf8mb4_bin');
});

test('indexes and foreign keys are added and removed', function () {
    ($this->createCustomers)();
    $orders = new TableRef($this->database, 'orders');
    $this->tables->apply($orders, new CreateTable([
        new ColumnDefinition('id', 'bigint', unsigned: true, nullable: false, autoIncrement: true),
        new ColumnDefinition('customer_id', 'bigint', unsigned: true, nullable: false),
    ], ['id']));

    $this->tables->apply($orders, new AddIndex(new IndexDefinition(IndexType::Index, ['customer_id'], 'orders_customer_index')));
    $this->tables->apply($orders, new AddForeignKey(new ForeignKeyDefinition(
        ['customer_id'], 'customers', ['id'], ForeignKeyAction::Cascade, ForeignKeyAction::Restrict, 'orders_customer_fk',
    )));

    $foreignKeys = $this->tables->foreignKeys($orders);
    expect($foreignKeys)->toHaveCount(1);
    expect($foreignKeys[0]->toArray())->toBe([
        'name' => 'orders_customer_fk',
        'columns' => ['customer_id'],
        'referencedDatabase' => $this->database,
        'referencedTable' => 'customers',
        'referencedColumns' => ['id'],
        'onUpdate' => 'RESTRICT',
        'onDelete' => 'CASCADE',
    ]);
    expect(array_map(fn ($index) => $index->name, $this->browser->indexes($orders)))->toBe(['PRIMARY', 'orders_customer_index']);

    $this->tables->apply($orders, new DropForeignKey('orders_customer_fk'));

    expect($this->tables->foreignKeys($orders))->toBe([]);
});

test('a foreign key between incompatible columns reports the MySQL reason', function () {
    ($this->createCustomers)();
    $customers = new TableRef($this->database, 'customers');

    expect(fn () => $this->tables->apply($customers, new AddForeignKey(new ForeignKeyDefinition(['email'], 'customers', ['id']))))
        ->toThrow(DatabaseOperationException::class, 'incompatible');
});

test('tables are renamed, copied with rows, truncated and dropped', function () {
    ($this->createCustomers)();
    $this->connection->statement("INSERT INTO `{$this->database}`.`customers` (email) VALUES ('a@example.test'), ('b@example.test')");

    $this->tables->apply(new TableRef($this->database, 'customers'), new RenameTable('clients'));
    $clients = new TableRef($this->database, 'clients');
    $this->tables->apply($clients, new CopyTable('clients_copy', withData: true));
    $copy = new TableRef($this->database, 'clients_copy');

    expect($this->browser->count($copy))->toBe(2);

    $this->tables->apply($copy, new TruncateTable);
    expect($this->browser->count($copy))->toBe(0);

    $this->tables->apply($copy, new DropTable);
    expect($this->browser->columns($copy))->toBe([]);
    expect($this->browser->count($clients))->toBe(2);
});

test('a change MySQL can only make by copying a big table waits for confirmation', function () {
    config(['dbnova.limits.locking_ddl_confirm_rows' => 0]);
    ($this->createCustomers)();
    $this->connection->statement("INSERT INTO `{$this->database}`.`customers` (email) VALUES ('a@example.test')");
    $this->connection->statement("ANALYZE TABLE `{$this->database}`.`customers`");
    $customers = new TableRef($this->database, 'customers');
    $toInteger = new ModifyColumn('note', new ColumnDefinition('note', 'int'));

    expect(fn () => $this->tables->apply($customers, $toInteger))->toThrow(LockingDdlRequiresConfirmationException::class);
    expect(columnTypes($this->browser->columns($customers))['note'])->toBe('text');

    expect($this->tables->apply($customers, $toInteger, allowLocking: true)->algorithm)->toBe('COPY');
    expect(columnTypes($this->browser->columns($customers))['note'])->toBe('int');
});

test('the preview shows exactly the statement that runs', function () {
    ($this->createCustomers)();
    $change = new AddColumn(new ColumnDefinition('nickname', 'varchar', '50', comment: "Customer's nickname"));

    $plan = $this->tables->preview(new TableRef($this->database, 'customers'), $change);

    expect($plan->statements)->toBe([
        "ALTER TABLE `{$this->database}`.`customers` ADD COLUMN `nickname` varchar(50) NULL COMMENT 'Customer\\'s nickname'",
    ]);
    expect($this->tables->apply(new TableRef($this->database, 'customers'), $change)->statements)->toBe($plan->statements);
});

test('maintenance runs on several tables and returns MySQL\'s rows', function () {
    ($this->createCustomers)();
    $this->connection->statement("CREATE TABLE `{$this->database}`.`legacy` (id INT PRIMARY KEY) ENGINE=MyISAM");
    $this->connection->statement("INSERT INTO `{$this->database}`.`customers` (email) VALUES ('a@example.com'), ('b@example.com')");

    $byTable = fn (array $results): array => collect($results)
        ->groupBy(fn (MaintenanceResult $row): string => $row->table)
        ->map(fn ($rows): array => $rows->map(fn (MaintenanceResult $row): string => $row->type.': '.$row->message)->all())
        ->all();

    $analyzed = $byTable($this->tables->maintain($this->database, ['customers', 'legacy'], MaintenanceOperation::Analyze));
    expect($analyzed["{$this->database}.customers"])->toContain('status: OK');

    $checked = $byTable($this->tables->maintain($this->database, ['customers'], MaintenanceOperation::Check));
    expect($checked["{$this->database}.customers"])->toBe(['status: OK']);

    // InnoDB has no in-place OPTIMIZE: MySQL rebuilds the table and says so in a note.
    $optimized = $byTable($this->tables->maintain($this->database, ['customers'], MaintenanceOperation::Optimize));
    expect($optimized["{$this->database}.customers"])->toContain('status: OK')
        ->and(implode("\n", $optimized["{$this->database}.customers"]))->toContain('recreate + analyze');

    // REPAIR works on MyISAM; for InnoDB MySQL reports a note instead of failing.
    $repaired = $byTable($this->tables->maintain($this->database, ['legacy', 'customers'], MaintenanceOperation::Repair));
    expect($repaired["{$this->database}.legacy"])->toContain('status: OK')
        ->and(implode("\n", $repaired["{$this->database}.customers"]))->toContain("doesn't support repair");
});

test('maintenance only accepts base tables that exist', function () {
    ($this->createCustomers)();
    $this->connection->statement("CREATE VIEW `{$this->database}`.`emails` AS SELECT email FROM `{$this->database}`.`customers`");

    expect(fn () => $this->tables->maintain($this->database, ['customers', 'nope'], MaintenanceOperation::Check))
        ->toThrow(DatabaseOperationException::class, 'The table "nope" does not exist');
    expect(fn () => $this->tables->maintain($this->database, ['emails'], MaintenanceOperation::Optimize))
        ->toThrow(DatabaseOperationException::class, 'The table "emails" does not exist');
    expect(fn () => $this->tables->maintain($this->database, ['x`; DROP DATABASE mysql; --'], MaintenanceOperation::Check))
        ->toThrow(DatabaseOperationException::class);
});

test('read-only mode allows CHECK but refuses changes', function () {
    ($this->createCustomers)();
    config(['dbnova.read_only' => true]);

    expect($this->tables->maintain($this->database, ['customers'], MaintenanceOperation::Check))->not->toBeEmpty();

    foreach ([MaintenanceOperation::Optimize, MaintenanceOperation::Analyze, MaintenanceOperation::Repair] as $operation) {
        expect(fn () => $this->tables->maintain($this->database, ['customers'], $operation))->toThrow(ReadOnlyModeException::class);
    }
});

test('table sizes are split into data, indexes and free space', function () {
    ($this->createCustomers)();
    $this->tables->apply(new TableRef($this->database, 'customers'), new AddIndex(new IndexDefinition(IndexType::Unique, ['email'], 'customers_email')));

    // InnoDB sizes a new index from its persistent statistics, which ANALYZE refreshes.
    $this->tables->maintain($this->database, ['customers'], MaintenanceOperation::Analyze);
    $info = app(DatabaseAdapterInterface::class)->schema()->table($this->database, 'customers');

    expect($info->dataBytes)->toBeGreaterThan(0)
        ->and($info->indexBytes)->toBeGreaterThan(0)
        ->and($info->freeBytes)->toBeInt()
        ->and($info->sizeBytes)->toBe($info->dataBytes + $info->indexBytes);
});

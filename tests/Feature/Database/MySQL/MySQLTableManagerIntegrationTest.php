<?php

use App\Database\Adapters\MySQL\MySQLConnection;
use App\Database\Contracts\DatabaseAdapterInterface;
use App\Database\DTOs\ColumnInfo;
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

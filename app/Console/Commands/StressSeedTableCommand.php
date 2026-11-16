<?php

namespace App\Console\Commands;

use App\Database\Adapters\MySQL\MySQLConnection;
use App\Database\Support\IdentifierQuoter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Builds a large table for performance testing. It connects as the
 * integration-test MySQL account and only writes to dbnova_test_* databases.
 */
#[Signature('dbnova:stress:seed-table
    {--rows=10000000 : Number of rows to insert}
    {--database=dbnova_test_stress : Target database (must start with dbnova_test_)}
    {--table=orders : Target table}')]
#[Description('Create a large table of synthetic rows for DBNova performance tests')]
class StressSeedTableCommand extends Command
{
    private const int BATCH_SIZE = 1_000_000;

    /**
     * Execute the console command.
     */
    public function handle(MySQLConnection $connection, IdentifierQuoter $quoter): int
    {
        $database = (string) $this->option('database');
        $table = (string) $this->option('table');
        $rows = max(1, (int) $this->option('rows'));

        if (! str_starts_with($database, 'dbnova_test_')) {
            $this->components->error('The target database must start with "dbnova_test_" so real data is never touched.');

            return self::FAILURE;
        }

        $username = config('dbnova.testing.username');

        if (! is_string($username) || $username === '') {
            $this->components->error('Set DBNOVA_TEST_DB_USERNAME and DBNOVA_TEST_DB_PASSWORD in .env first (see .claude/docs/TESTING.md).');

            return self::FAILURE;
        }

        $connection->useCredentials(['username' => $username, 'password' => (string) config('dbnova.testing.password')]);

        $qualifiedTable = $quoter->quote($database).'.'.$quoter->quote($table);
        $digits = $quoter->quote($database).'.`dbnova_digits`';

        $connection->statement('CREATE DATABASE IF NOT EXISTS '.$quoter->quote($database));
        $connection->statement("DROP TABLE IF EXISTS {$qualifiedTable}");
        $connection->statement("CREATE TABLE {$qualifiedTable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            uuid CHAR(36) NOT NULL,
            customer VARCHAR(100) NOT NULL,
            email VARCHAR(150) NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            status ENUM('new','paid','shipped','cancelled') NOT NULL,
            notes TEXT NULL,
            attachment BLOB NULL,
            created_at DATETIME NOT NULL,
            KEY orders_status_index (status),
            KEY orders_created_at_index (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $connection->statement("CREATE TABLE IF NOT EXISTS {$digits} (d TINYINT UNSIGNED NOT NULL PRIMARY KEY)");
        $connection->statement("INSERT IGNORE INTO {$digits} VALUES (0),(1),(2),(3),(4),(5),(6),(7),(8),(9)");

        $progress = $this->output->createProgressBar((int) ceil($rows / self::BATCH_SIZE));
        $progress->start();

        for ($start = 0; $start < $rows; $start += self::BATCH_SIZE) {
            $connection->statement(
                "INSERT INTO {$qualifiedTable} (uuid, customer, email, amount, status, notes, attachment, created_at)
                SELECT UUID(),
                    CONCAT('customer_', n),
                    CONCAT('customer', n, '@example.test'),
                    ROUND(RAND() * 10000, 2),
                    ELT(1 + n % 4, 'new', 'paid', 'shipped', 'cancelled'),
                    IF(n % 10 = 0, NULL, REPEAT('lorem ipsum ', 1 + n % 40)),
                    IF(n % 100 = 0, RANDOM_BYTES(64), NULL),
                    NOW() - INTERVAL (n % 525600) MINUTE
                FROM (
                    SELECT ? + d1.d + d2.d * 10 + d3.d * 100 + d4.d * 1000 + d5.d * 10000 + d6.d * 100000 AS n
                    FROM {$digits} d1, {$digits} d2, {$digits} d3, {$digits} d4, {$digits} d5, {$digits} d6
                ) numbers
                WHERE n < ?",
                [$start, $rows],
            );

            $progress->advance();
        }

        $progress->finish();
        $this->newLine();
        $connection->statement("DROP TABLE {$digits}");

        $this->components->info("Seeded {$rows} rows into {$database}.{$table}.");

        return self::SUCCESS;
    }
}

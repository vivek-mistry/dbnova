<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Writes a synthetic dump shaped like mysqldump output (extended INSERTs of
 * about 1 MB, strings with quotes and semicolons) for import stress tests.
 * It streams to disk, so any size can be generated with constant memory.
 */
#[Signature('dbnova:stress:make-dump
    {--size=2G : Approximate size of the dump (e.g. 500M, 2G, 10G)}
    {--path= : Where to write it (default: storage/app/dbnova/tmp/stress-dump.sql)}
    {--gzip : Write a .sql.gz instead}')]
#[Description('Generate a large synthetic SQL dump for DBNova import stress tests')]
class StressMakeDumpCommand extends Command
{
    /** Rows per INSERT; about 1 MB per statement like mysqldump's default. */
    private const int ROWS_PER_INSERT = 5000;

    private const int TABLES = 4;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $targetBytes = $this->parseSize((string) $this->option('size'));

        if ($targetBytes === null) {
            $this->error('Use a size like 500M, 2G or 10G.');

            return self::FAILURE;
        }

        $gzip = (bool) $this->option('gzip');
        $path = (string) ($this->option('path') ?: config()->string('dbnova.paths.tmp').'/stress-dump.sql'.($gzip ? '.gz' : ''));
        File::ensureDirectoryExists(dirname($path));

        $handle = $gzip ? gzopen($path, 'wb6') : fopen($path, 'wb');

        if ($handle === false) {
            $this->error("Cannot write {$path}.");

            return self::FAILURE;
        }

        $write = fn (string $text): int|false => $gzip ? gzwrite($handle, $text) : fwrite($handle, $text);
        $written = 0;
        $rows = 0;
        $startedAt = microtime(true);

        $header = "-- DBNova stress dump\n/*!40101 SET NAMES utf8mb4 */;\n/*!40014 SET FOREIGN_KEY_CHECKS=0 */;\n";

        for ($table = 1; $table <= self::TABLES; $table++) {
            $header .= "DROP TABLE IF EXISTS `stress_{$table}`;\n"
                ."CREATE TABLE `stress_{$table}` (\n  `id` bigint NOT NULL,\n  `customer` varchar(64) NOT NULL,\n"
                ."  `note` varchar(255) DEFAULT NULL,\n  `amount` decimal(12,2) NOT NULL,\n  `created_at` datetime NOT NULL,\n"
                ."  PRIMARY KEY (`id`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n";
        }

        $written += (int) $write($header);
        $bar = $this->output->createProgressBar(100);

        while ($written < $targetBytes) {
            $table = intdiv($rows, self::ROWS_PER_INSERT) % self::TABLES + 1;
            $values = [];

            for ($index = 0; $index < self::ROWS_PER_INSERT; $index++) {
                $id = ++$rows;
                $values[] = sprintf(
                    "(%d,'customer-%d','Note %d: it\\'s; \"quoted\" -- not a comment',%d.%02d,'2026-%02d-%02d 12:00:00')",
                    $id, $id % 100000, $id, $id % 10000, $id % 100, $id % 12 + 1, $id % 28 + 1,
                );
            }

            $written += (int) $write("INSERT INTO `stress_{$table}` VALUES ".implode(',', $values).";\n");
            $bar->setProgress((int) min(100, $written / $targetBytes * 100));
        }

        $written += (int) $write("/*!40014 SET FOREIGN_KEY_CHECKS=1 */;\n");
        $gzip ? gzclose($handle) : fclose($handle);
        $bar->finish();
        $this->newLine();

        $this->info(sprintf(
            'Wrote %s rows (%.1f MB of SQL) to %s in %.1f s. Rows per table: about %s.',
            number_format($rows),
            $written / 1048576,
            $path,
            microtime(true) - $startedAt,
            number_format(intdiv($rows, self::TABLES)),
        ));

        return self::SUCCESS;
    }

    private function parseSize(string $size): ?int
    {
        if (preg_match('/^(\d+(?:\.\d+)?)\s*([KMG]?)B?$/i', trim($size), $matches) !== 1) {
            return null;
        }

        $multiplier = match (strtoupper($matches[2])) {
            'K' => 1024,
            'M' => 1024 ** 2,
            'G' => 1024 ** 3,
            default => 1,
        };

        return (int) ((float) $matches[1] * $multiplier);
    }
}

<?php

namespace App\Database\Adapters\MySQL;

use App\Database\Contracts\ExporterInterface;
use App\Database\DTOs\Export\CsvExportRequest;
use App\Database\DTOs\Export\ExportRequest;
use App\Database\DTOs\Export\ExportResult;
use App\Database\Exceptions\DatabaseOperationException;
use App\Database\Export\ExportFileWriter;
use App\Database\Processes\ProcessRunner;
use App\Database\Sql\SqlStreamScanner;
use App\Database\Support\IdentifierQuoter;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;

/**
 * Dumps with mysqldump and streams its stdout to a file, optionally through
 * an incremental gzip context. PHP memory stays flat: output is written as
 * it arrives and never accumulated.
 *
 * The dump has no CREATE DATABASE / USE, so it imports into any database
 * (DBNova's importer, phpMyAdmin, the mysql client with --database).
 *
 * CSV exports read through an unbuffered PDO cursor (rows stream from
 * MySQL as they are written to the file) inside a read-only transaction.
 */
class MySQLExporter implements ExporterInterface
{
    /** mysqldump ends a complete dump with this comment; without it the dump is incomplete. */
    private const string COMPLETION_MARKER = '-- Dump completed';

    /** The longest error message stored. */
    private const int MAX_ERROR_LENGTH = 2000;

    /** CSV rows are written in pieces of about this size. */
    private const int CSV_FLUSH_BYTES = 1_048_576;

    /** Progress is reported at least every this many CSV rows. */
    private const int CSV_PROGRESS_ROWS = 5000;

    public function __construct(
        private ProcessRunner $processRunner,
        private MySQLConnection $connection,
        private IdentifierQuoter $quoter,
    ) {}

    public function export(ExportRequest $request, callable $onProgress): ExportResult
    {
        $this->connection->useCredentials($request->credentials);
        $this->assertExportable($request);

        $writer = ExportFileWriter::create($request->path, $request->format->isCompressed());
        $tail = '';

        try {
            $result = $this->processRunner->streamOutput(
                'mysqldump',
                $request->credentials,
                $this->arguments($request),
                function (string $output) use ($writer, &$tail): void {
                    $tail = substr($tail.$output, -256);
                    $writer->write($output);
                },
                function () use ($onProgress, $writer): void {
                    $onProgress($writer->bytesWritten, $writer->fileBytes);
                },
            );

            $writer->finish();
        } catch (Throwable $exception) {
            $writer->abort();

            throw $exception;
        }

        $error = match (true) {
            $result['exitCode'] !== 0 => $this->errorMessage($result['errorOutput'], $result['exitCode']),
            ! str_contains($tail, self::COMPLETION_MARKER) => 'mysqldump exited without finishing the dump, so the file would be incomplete.',
            default => null,
        };

        if ($error !== null) {
            $writer->abort();

            return new ExportResult(false, $writer->bytesWritten, $writer->fileBytes, $error);
        }

        return new ExportResult(true, $writer->bytesWritten, $writer->fileBytes);
    }

    public function exportCsv(CsvExportRequest $request, callable $onProgress, ?callable $onStart = null): ExportResult
    {
        $this->connection->useCredentials($request->credentials);
        $sql = $this->csvQuery($request);

        // Connect before creating the file, so a connection failure leaves nothing behind.
        $pdo = $this->connection->pdo();
        $connectionId = $this->connection->connectionId();
        $writer = ExportFileWriter::create($request->path, $request->format->isCompressed());
        $rows = 0;
        $pdoStatement = null;

        try {
            if ($request->database !== null && $request->database !== '') {
                $pdo->exec('USE '.$this->quoter->quote($request->database));
            }

            // A long export is not a runaway editor query: no time limit.
            $pdo->exec('SET SESSION max_execution_time = 0');
            // Writes (even from stored functions) fail inside a read-only transaction; it is also a consistent
            // snapshot. The session default is read-only too, so even a COMMIT would not re-open writes.
            $pdo->exec('SET SESSION TRANSACTION READ ONLY');
            $pdo->exec('START TRANSACTION READ ONLY');

            $onProgress(0, 0, 0);

            if ($onStart !== null) {
                $onStart($connectionId);
            }

            // The text protocol, like the SQL editor: the user's statement is sent as typed.
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
            $this->connection->setUnbuffered(true);
            $pdoStatement = $pdo->query($sql);

            if ($pdoStatement === false || $pdoStatement->columnCount() === 0) {
                throw new DatabaseOperationException('The statement returned no result set to export.');
            }

            // MySQL has started sending rows: report in (ends the heartbeat lease, notices a cancel).
            $onProgress(0, 0, 0);

            $writer->write(self::csvLine($this->columnNames($pdoStatement)));
            $buffer = '';

            while (($row = $pdoStatement->fetch(PDO::FETCH_NUM)) !== false) {
                $buffer .= self::csvLine($row);
                $rows++;

                if (strlen($buffer) >= self::CSV_FLUSH_BYTES || $rows % self::CSV_PROGRESS_ROWS === 0) {
                    $writer->write($buffer);
                    $buffer = '';
                    $onProgress($writer->bytesWritten, $writer->fileBytes, $rows);
                }
            }

            $writer->write($buffer);
            $pdoStatement->closeCursor();
            $writer->finish();
        } catch (PDOException $exception) {
            $this->stopQuery($pdoStatement, $connectionId);
            $writer->abort();

            throw DatabaseOperationException::fromPdoException($exception);
        } catch (Throwable $exception) {
            $this->stopQuery($pdoStatement, $connectionId);
            $writer->abort();

            throw $exception;
        } finally {
            $this->resetSession($pdo);
        }

        return new ExportResult(true, $writer->bytesWritten, $writer->fileBytes, rowsWritten: $rows);
    }

    public function estimateBytes(string $database, array $tables = []): int
    {
        $sql = 'SELECT COALESCE(SUM(DATA_LENGTH), 0) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?';

        if ($tables !== []) {
            $sql .= ' AND TABLE_NAME IN ('.implode(', ', array_fill(0, count($tables), '?')).')';
        }

        return (int) $this->connection->scalar($sql, [$database, ...$tables]);
    }

    /**
     * mysqldump would fail on a missing table anyway, but checking first
     * gives a clear message and makes sure every name is a real one.
     *
     * @throws DatabaseOperationException
     */
    private function assertExportable(ExportRequest $request): void
    {
        $exists = $this->connection->scalar('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$request->database]);

        if ((int) $exists === 0) {
            throw new DatabaseOperationException('The database `'.$request->database.'` does not exist (any more).');
        }

        if ($request->tables === []) {
            return;
        }

        $existing = array_column($this->connection->select(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?',
            [$request->database],
        ), 'TABLE_NAME');
        $missing = array_diff($request->tables, $existing);

        if ($missing !== []) {
            throw new DatabaseOperationException('These tables no longer exist: '.implode(', ', array_slice($missing, 0, 10)).'.');
        }
    }

    /**
     * mysqldump flags (see IMPORT_EXPORT_ENGINE.md). Names follow "--", so
     * a database or table name can never be read as an option.
     *
     * @return list<string>
     */
    private function arguments(ExportRequest $request): array
    {
        $arguments = [
            '--single-transaction',
            '--quick',
            '--hex-blob',
            '--default-character-set=utf8mb4',
            '--set-gtid-purged=OFF',
            '--no-tablespaces',
        ];

        if (version_compare((string) $this->connection->scalar('SELECT VERSION()'), '8.0.0', '<')) {
            $arguments[] = '--column-statistics=0'; // An 8.0 client against a 5.7 server.
        }

        if ($request->content === 'structure') {
            $arguments[] = '--no-data';
        }

        if ($request->content === 'data') {
            $arguments[] = '--no-create-info';
        }

        if ($request->includePrograms && $request->content !== 'data') {
            array_push($arguments, '--routines', '--events', '--triggers');
        } else {
            $arguments[] = '--skip-triggers';
        }

        return [...$arguments, '--', $request->database, ...$request->tables];
    }

    private function errorMessage(string $errorOutput, int $exitCode): string
    {
        $lines = array_values(array_filter(
            array_map(trim(...), explode("\n", $errorOutput)),
            fn (string $line): bool => $line !== '' && ! str_starts_with($line, 'mysqldump: [Warning]'),
        ));

        $message = $lines === []
            ? 'mysqldump stopped with exit code '.$exitCode.' and no error message.'
            : implode("\n", array_slice($lines, -3));

        return mb_strimwidth($message, 0, self::MAX_ERROR_LENGTH, '…');
    }

    /**
     * The SELECT for a table, or the user's statement after checking that
     * it is exactly one read-only query that returns rows.
     *
     * @throws DatabaseOperationException
     */
    private function csvQuery(CsvExportRequest $request): string
    {
        if ($request->table !== null) {
            $database = (string) $request->database;
            $exists = $this->connection->scalar(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
                [$database, $request->table],
            );

            if ((int) $exists === 0) {
                throw new DatabaseOperationException('The table `'.$request->table.'` does not exist (any more).');
            }

            return 'SELECT * FROM '.$this->quoter->quote($database).'.'.$this->quoter->quote($request->table);
        }

        $statements = SqlStreamScanner::split((string) $request->query);

        if (count($statements) !== 1) {
            throw new DatabaseOperationException('Only a single statement can be exported to CSV.');
        }

        $problem = $statements[0]->exportProblem();

        if ($problem !== null) {
            throw new DatabaseOperationException($problem);
        }

        if ($request->database !== null && $request->database !== '') {
            $exists = $this->connection->scalar('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$request->database]);

            if ((int) $exists === 0) {
                throw new DatabaseOperationException('The database `'.$request->database.'` does not exist (any more).');
            }
        }

        return $statements[0]->sql;
    }

    /**
     * One RFC 4180 record. NULL is an empty unquoted field; every string is
     * quoted, so an empty string ("") stays distinguishable from NULL.
     * Binary values (not valid UTF-8) are written as 0x-prefixed hex.
     *
     * @param  array<int, mixed>  $values
     */
    public static function csvLine(array $values): string
    {
        $fields = [];

        foreach ($values as $value) {
            $fields[] = match (true) {
                $value === null => '',
                is_int($value), is_float($value) => (string) $value,
                is_bool($value) => $value ? '1' : '0',
                default => '"'.str_replace('"', '""', self::csvText((string) $value)).'"',
            };
        }

        return implode(',', $fields)."\r\n";
    }

    private static function csvText(string $value): string
    {
        return mb_check_encoding($value, 'UTF-8') ? $value : '0x'.bin2hex($value);
    }

    /**
     * @return list<string>
     */
    private function columnNames(PDOStatement $pdoStatement): array
    {
        $names = [];

        for ($index = 0; $index < $pdoStatement->columnCount(); $index++) {
            $meta = $pdoStatement->getColumnMeta($index);
            $names[] = $meta === false ? 'column '.($index + 1) : $meta['name'];
        }

        return $names;
    }

    /**
     * After a cancel or error mid-stream: stop MySQL sending the rest of
     * the result instead of reading millions of rows just to discard them.
     */
    private function stopQuery(PDOStatement|false|null $pdoStatement, int $connectionId): void
    {
        if (! $pdoStatement instanceof PDOStatement) {
            return;
        }

        try {
            $this->connection->killQuery($connectionId);
        } catch (DatabaseOperationException) {
            // Best effort: closing the cursor below still frees the statement.
        }

        try {
            $pdoStatement->closeCursor();
        } catch (PDOException) {
            // The query was interrupted; nothing left to read.
        }
    }

    private function resetSession(PDO $pdo): void
    {
        $this->connection->setUnbuffered(false);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

        try {
            $pdo->exec('ROLLBACK');
            $pdo->exec('SET SESSION TRANSACTION READ WRITE');
            $pdo->exec('SET SESSION max_execution_time = '.config()->integer('dbnova.limits.query_timeout_ms'));
        } catch (PDOException) {
            // The connection is not reused after a failed export.
        }
    }
}

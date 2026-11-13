<?php

namespace App\Database\Adapters\MySQL;

use App\Database\Contracts\ExporterInterface;
use App\Database\DTOs\Export\ExportRequest;
use App\Database\DTOs\Export\ExportResult;
use App\Database\Exceptions\DatabaseOperationException;
use App\Database\Processes\ProcessRunner;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Dumps with mysqldump and streams its stdout to a file, optionally through
 * an incremental gzip context. PHP memory stays flat: output is written as
 * it arrives and never accumulated.
 *
 * The dump has no CREATE DATABASE / USE, so it imports into any database
 * (DBNova's importer, phpMyAdmin, the mysql client with --database).
 */
class MySQLExporter implements ExporterInterface
{
    /** mysqldump ends a complete dump with this comment; without it the dump is incomplete. */
    private const string COMPLETION_MARKER = '-- Dump completed';

    /** The longest error message stored. */
    private const int MAX_ERROR_LENGTH = 2000;

    public function __construct(
        private ProcessRunner $processRunner,
        private MySQLConnection $connection,
    ) {}

    public function export(ExportRequest $request, callable $onProgress): ExportResult
    {
        $this->connection->useCredentials($request->credentials);
        $this->assertExportable($request);

        File::ensureDirectoryExists(dirname($request->path), 0700);
        $previousUmask = umask(0077);
        $handle = @fopen($request->path, 'xb');
        umask($previousUmask);

        if ($handle === false) {
            throw new DatabaseOperationException('The export file could not be created.');
        }

        $deflate = $request->format->isCompressed() ? deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]) : null;

        if ($deflate === false) {
            fclose($handle);
            File::delete($request->path);

            throw new DatabaseOperationException('Gzip compression is not available (the zlib extension is missing).');
        }
        $bytesWritten = 0;
        $fileBytes = 0;
        $tail = '';

        $write = function (string $data) use ($handle, &$fileBytes): void {
            if ($data === '') {
                return;
            }

            $written = fwrite($handle, $data);

            if ($written !== strlen($data)) {
                throw new DatabaseOperationException('Writing the export file failed. The disk may be full.');
            }

            $fileBytes += $written;
        };

        try {
            $result = $this->processRunner->streamOutput(
                'mysqldump',
                $request->credentials,
                $this->arguments($request),
                function (string $output) use ($deflate, $write, &$bytesWritten, &$tail): void {
                    $bytesWritten += strlen($output);
                    $tail = substr($tail.$output, -256);
                    $write($deflate === null ? $output : (string) deflate_add($deflate, $output, ZLIB_NO_FLUSH));
                },
                function () use ($onProgress, &$bytesWritten, &$fileBytes): void {
                    $onProgress($bytesWritten, $fileBytes);
                },
            );

            if ($deflate !== null) {
                $write((string) deflate_add($deflate, '', ZLIB_FINISH));
            }

            fclose($handle);
        } catch (Throwable $exception) {
            fclose($handle);
            File::delete($request->path);

            throw $exception;
        }

        $error = match (true) {
            $result['exitCode'] !== 0 => $this->errorMessage($result['errorOutput'], $result['exitCode']),
            ! str_contains($tail, self::COMPLETION_MARKER) => 'mysqldump exited without finishing the dump, so the file would be incomplete.',
            default => null,
        };

        if ($error !== null) {
            File::delete($request->path);

            return new ExportResult(false, $bytesWritten, $fileBytes, $error);
        }

        return new ExportResult(true, $bytesWritten, $fileBytes);
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
}

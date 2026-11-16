<?php

namespace App\Database\Contracts;

use App\Database\DTOs\Export\CsvExportRequest;
use App\Database\DTOs\Export\ExportRequest;
use App\Database\DTOs\Export\ExportResult;
use App\Database\Exceptions\DatabaseOperationException;

/**
 * Dumps a database (or some of its tables) to a file. Everything streams:
 * memory use does not grow with the size of the database.
 */
interface ExporterInterface
{
    /**
     * Write the dump to $request->path. A failed or stopped export leaves
     * no file behind. Exports only read, so read-only mode allows them.
     *
     * @param  callable(int $bytesWritten, int $fileBytes): void  $onProgress  Called every few milliseconds;
     *                                                                         it may throw to stop the export
     *                                                                         (the dump tool is terminated).
     *
     * @throws DatabaseOperationException when the database or a table does not exist, or the file cannot be written
     */
    public function export(ExportRequest $request, callable $onProgress): ExportResult;

    /**
     * Write one table, or the rows of one read-only statement, as CSV
     * (RFC 4180, UTF-8, header row) through an unbuffered cursor inside a
     * read-only transaction. A failed or stopped export leaves no file.
     *
     * @param  callable(int $bytesWritten, int $fileBytes, int $rowsWritten): void  $onProgress  Called before the
     *                                                                                           query runs and every
     *                                                                                           few thousand rows;
     *                                                                                           it may throw to stop.
     * @param  (callable(int $connectionId): void)|null  $onStart  Receives the MySQL connection id before the query runs (for KILL QUERY).
     *
     * @throws DatabaseOperationException when the table is missing, the statement is not a single read-only query, or MySQL fails
     */
    public function exportCsv(CsvExportRequest $request, callable $onProgress, ?callable $onStart = null): ExportResult;

    /**
     * The size of the tables' data and indexes from the catalog: a rough
     * guide to the dump size, always shown as an estimate.
     *
     * @param  list<string>  $tables  Empty means the whole database.
     */
    public function estimateBytes(string $database, array $tables = []): int;
}

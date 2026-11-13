<?php

namespace App\Database\Contracts;

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
     * The size of the tables' data and indexes from the catalog: a rough
     * guide to the dump size, always shown as an estimate.
     *
     * @param  list<string>  $tables  Empty means the whole database.
     */
    public function estimateBytes(string $database, array $tables = []): int;
}

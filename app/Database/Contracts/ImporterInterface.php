<?php

namespace App\Database\Contracts;

use App\Database\DTOs\Import\ImportRequest;
use App\Database\DTOs\Import\ImportResult;
use App\Database\DTOs\Import\PreflightReport;
use App\Database\Exceptions\DatabaseOperationException;
use App\Database\Exceptions\ReadOnlyModeException;
use App\Database\Import\SourceStream;

/**
 * Imports SQL dumps into one database. Everything streams: memory use is
 * bounded by the largest single statement, never by the file size.
 */
interface ImporterInterface
{
    /**
     * Scan the whole dump once and decide whether it can be imported into
     * one database. Problems with the file itself (corrupt, truncated,
     * oversized statement) become problems in the report, not exceptions.
     *
     * @param  (callable(int $position, int $statements): void)|null  $onProgress  Called after each chunk;
     *                                                                             it may throw to stop the scan.
     */
    public function preflight(SourceStream $source, ?callable $onProgress = null): PreflightReport;

    /**
     * Stream the dump into the target database through the mysql client.
     * Statements are sent as written, except the source database's
     * USE / CREATE / DROP / ALTER DATABASE, which are skipped. Nothing is
     * rolled back on failure: a failed import is partial.
     *
     * @param  callable(int $sourcePosition, int $bytesSent, int $statementsSent): void  $onProgress  Called
     *                                                                                                about every
     *                                                                                                millisecond; it
     *                                                                                                may throw to
     *                                                                                                stop (the client
     *                                                                                                is terminated).
     *
     * @throws DatabaseOperationException when the server or target is not ready
     * @throws ReadOnlyModeException
     */
    public function import(ImportRequest $request, SourceStream $source, callable $onProgress): ImportResult;
}

<?php

namespace App\Database\Contracts;

use App\Database\DTOs\Import\PreflightReport;
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
}

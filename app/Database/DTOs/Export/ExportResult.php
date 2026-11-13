<?php

namespace App\Database\DTOs\Export;

final readonly class ExportResult
{
    /**
     * @param  int  $bytesWritten  SQL bytes produced by the dump tool (before compression).
     * @param  int  $fileBytes  Bytes in the file on disk.
     * @param  int|null  $rowsWritten  Data rows written (CSV only).
     */
    public function __construct(
        public bool $succeeded,
        public int $bytesWritten,
        public int $fileBytes,
        public ?string $error = null,
        public ?int $rowsWritten = null,
    ) {}
}

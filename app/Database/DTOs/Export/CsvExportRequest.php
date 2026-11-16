<?php

namespace App\Database\DTOs\Export;

use App\Enums\ExportFormat;

/**
 * A CSV export of one table, or of one read-only statement from the SQL
 * editor (run again in the worker, inside a read-only transaction).
 */
final readonly class CsvExportRequest
{
    /**
     * @param  array{username: string, password: string}  $credentials
     * @param  string|null  $database  Required for a table; the default database for a query.
     * @param  bool  $spreadsheetSafe  Prefix text that a spreadsheet would run as a formula with a single quote.
     */
    public function __construct(
        public array $credentials,
        public string $path,
        public ExportFormat $format,
        public ?string $database,
        public ?string $table = null,
        public ?string $query = null,
        public bool $spreadsheetSafe = false,
    ) {}
}

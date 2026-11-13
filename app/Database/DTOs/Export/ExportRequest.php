<?php

namespace App\Database\DTOs\Export;

use App\Enums\ExportFormat;

/**
 * What to dump where. Credentials come from the export record (the queued
 * job has no session) and are never logged or put in argv.
 */
final readonly class ExportRequest
{
    /**
     * @param  array{username: string, password: string}  $credentials
     * @param  list<string>  $tables  Empty means the whole database.
     * @param  'both'|'structure'|'data'  $content
     * @param  bool  $includePrograms  Dump routines, triggers and events too (routines and events always cover the whole database).
     */
    public function __construct(
        public string $database,
        public array $credentials,
        public string $path,
        public ExportFormat $format = ExportFormat::Sql,
        public array $tables = [],
        public string $content = 'both',
        public bool $includePrograms = true,
    ) {}
}

<?php

namespace App\Database\DTOs\Import;

/**
 * What to import where. Credentials come from the import record (the
 * queued job has no session) and are never logged or put in argv.
 */
final readonly class ImportRequest
{
    /**
     * @param  array{username: string, password: string}  $credentials
     * @param  string|null  $sourceDatabase  The database the dump was made for; its USE / CREATE DATABASE statements are skipped.
     * @param  bool  $speedMode  Turn off foreign key and unique checks and autocommit for the session (faster, not atomic).
     * @param  bool  $trustCompoundPrograms  The user confirmed the dump's multi-statement routine/trigger/event bodies may run (they cannot be checked).
     */
    public function __construct(
        public string $database,
        public array $credentials,
        public ?string $sourceDatabase = null,
        public bool $speedMode = false,
        public bool $trustCompoundPrograms = false,
    ) {}
}

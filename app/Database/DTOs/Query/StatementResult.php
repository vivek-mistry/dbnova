<?php

namespace App\Database\DTOs\Query;

/**
 * The outcome of one statement in a SQL editor run.
 */
final readonly class StatementResult
{
    /**
     * @param  'ok'|'error'|'skipped'  $status  "skipped" means an earlier statement failed, so this one did not run.
     * @param  list<string>|null  $columns  Null when the statement returned no result set.
     * @param  list<list<mixed>>|null  $rows  Cells are encoded like table pages (binary length, long-text preview).
     * @param  list<array{level: string, code: int, message: string}>  $warnings
     */
    public function __construct(
        public int $line,
        public string $sql,
        public string $keyword,
        public string $status,
        public ?array $columns = null,
        public ?array $rows = null,
        public bool $rowsTruncated = false,
        public ?int $affectedRows = null,
        public ?string $lastInsertId = null,
        public array $warnings = [],
        public ?string $error = null,
        public ?int $errorCode = null,
        public float $durationMs = 0.0,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'line' => $this->line,
            'sql' => mb_strlen($this->sql) > 2000 ? mb_substr($this->sql, 0, 2000).'…' : $this->sql,
            'keyword' => $this->keyword,
            'status' => $this->status,
            'columns' => $this->columns,
            'rows' => $this->rows,
            'rowsTruncated' => $this->rowsTruncated,
            'affectedRows' => $this->affectedRows,
            'lastInsertId' => $this->lastInsertId,
            'warnings' => $this->warnings,
            'error' => $this->error,
            'errorCode' => $this->errorCode,
            'durationMs' => round($this->durationMs, 1),
        ];
    }
}

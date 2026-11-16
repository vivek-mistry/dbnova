<?php

namespace App\Database\DTOs\Query;

final readonly class QueryRun
{
    /**
     * @param  list<StatementResult>  $statements
     */
    public function __construct(public array $statements) {}

    public function succeeded(): bool
    {
        foreach ($this->statements as $statement) {
            if ($statement->status !== 'ok') {
                return false;
            }
        }

        return true;
    }

    public function firstError(): ?StatementResult
    {
        foreach ($this->statements as $statement) {
            if ($statement->status === 'error') {
                return $statement;
            }
        }

        return null;
    }

    public function durationMs(): float
    {
        return array_sum(array_map(fn (StatementResult $statement): float => $statement->durationMs, $this->statements));
    }

    /**
     * @return array{statements: list<array<string, mixed>>, succeeded: bool, durationMs: float}
     */
    public function toArray(): array
    {
        return [
            'statements' => array_map(fn (StatementResult $statement): array => $statement->toArray(), $this->statements),
            'succeeded' => $this->succeeded(),
            'durationMs' => round($this->durationMs(), 1),
        ];
    }
}

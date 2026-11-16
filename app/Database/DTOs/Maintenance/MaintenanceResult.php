<?php

namespace App\Database\DTOs\Maintenance;

/**
 * One result row of OPTIMIZE / ANALYZE / CHECK / REPAIR TABLE, as MySQL reports it.
 */
final readonly class MaintenanceResult
{
    /**
     * @param  string  $table  "database.table", as MySQL writes it.
     * @param  string  $type  Msg_type: status, error, info, note or warning.
     */
    public function __construct(
        public string $table,
        public string $operation,
        public string $type,
        public string $message,
    ) {}

    /**
     * @return array{table: string, operation: string, type: string, message: string}
     */
    public function toArray(): array
    {
        return [
            'table' => $this->table,
            'operation' => $this->operation,
            'type' => $this->type,
            'message' => $this->message,
        ];
    }
}

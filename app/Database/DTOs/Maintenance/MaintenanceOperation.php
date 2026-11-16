<?php

namespace App\Database\DTOs\Maintenance;

/**
 * Table maintenance statements. Each one reports its outcome as result rows.
 */
enum MaintenanceOperation: string
{
    case Optimize = 'optimize';
    case Analyze = 'analyze';
    case Check = 'check';
    case Repair = 'repair';

    public function keyword(): string
    {
        return strtoupper($this->value);
    }

    /**
     * CHECK TABLE only reads; the others rebuild the table, update its statistics or rewrite its files.
     */
    public function changesServer(): bool
    {
        return $this !== self::Check;
    }
}

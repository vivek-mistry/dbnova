<?php

namespace App\Database\Adapters\MySQL;

use App\Database\Contracts\ServerInspectorInterface;
use App\Database\DTOs\ServerInfo;

class MySQLServerInspector implements ServerInspectorInterface
{
    public function __construct(private MySQLConnection $connection) {}

    public function info(): ServerInfo
    {
        $uptime = $this->connection->select("SHOW GLOBAL STATUS LIKE 'Uptime'")[0]['Value'] ?? 0;

        $totals = $this->connection->select(
            'SELECT
                (SELECT COUNT(*) FROM information_schema.SCHEMATA) AS database_count,
                (SELECT COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH), 0) FROM information_schema.TABLES) AS total_size',
        )[0];

        return new ServerInfo(
            version: (string) $this->connection->scalar('SELECT VERSION()'),
            uptimeSeconds: (int) $uptime,
            databaseCount: (int) $totals['database_count'],
            totalSizeBytes: (int) $totals['total_size'],
        );
    }
}

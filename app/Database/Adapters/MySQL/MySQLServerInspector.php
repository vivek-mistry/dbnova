<?php

namespace App\Database\Adapters\MySQL;

use App\Database\Contracts\ServerInspectorInterface;
use App\Database\DTOs\ProcessInfo;
use App\Database\DTOs\ServerInfo;
use App\Database\DTOs\ServerMetrics;
use App\Database\Exceptions\DatabaseOperationException;
use App\Database\Support\ReadOnlyGuard;

class MySQLServerInspector implements ServerInspectorInterface
{
    /** A process's SQL is cut to this many characters in the list. */
    private const int MAX_INFO_LENGTH = 4000;

    /** MySQL: "Unknown thread id". */
    private const int UNKNOWN_THREAD = 1094;

    /** The status counters metrics() reads. */
    private const array METRIC_COUNTERS = [
        'Uptime', 'Questions', 'Threads_connected', 'Threads_running',
        'Innodb_buffer_pool_pages_total', 'Innodb_buffer_pool_pages_free', 'Bytes_received', 'Bytes_sent',
    ];

    public function __construct(
        private MySQLConnection $connection,
        private ReadOnlyGuard $readOnlyGuard,
    ) {}

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

    public function processes(): array
    {
        $ownId = $this->connection->connectionId();

        $processes = array_map(fn (array $row): ProcessInfo => new ProcessInfo(
            id: (int) $row['Id'],
            user: (string) $row['User'],
            host: $row['Host'] === null || $row['Host'] === '' ? null : (string) $row['Host'],
            database: $row['db'] === null ? null : (string) $row['db'],
            command: (string) $row['Command'],
            timeSeconds: max(0, (int) $row['Time']),
            state: $row['State'] === null || $row['State'] === '' ? null : (string) $row['State'],
            info: $row['Info'] === null ? null : mb_strimwidth((string) $row['Info'], 0, self::MAX_INFO_LENGTH, '…'),
            isCurrentConnection: (int) $row['Id'] === $ownId,
        ), $this->connection->select('SHOW FULL PROCESSLIST'));

        usort($processes, fn (ProcessInfo $a, ProcessInfo $b): int => [$b->timeSeconds, $a->id] <=> [$a->timeSeconds, $b->id]);

        return $processes;
    }

    public function kill(int $processId, bool $connection = false): bool
    {
        $this->readOnlyGuard->assertWritable();

        if ($processId <= 0) {
            throw new DatabaseOperationException('That is not a process id.');
        }

        if ($processId === $this->connection->connectionId()) {
            throw new DatabaseOperationException('That is the connection DBNova is using for you; it cannot be killed from here.');
        }

        try {
            // The id is an integer, so it is safe to interpolate (KILL takes no placeholders).
            $this->connection->statement(($connection ? 'KILL ' : 'KILL QUERY ').$processId);
        } catch (DatabaseOperationException $exception) {
            if ($exception->getCode() === self::UNKNOWN_THREAD) {
                return false;
            }

            throw $exception;
        }

        return true;
    }

    public function variables(): array
    {
        return $this->nameValueRows('SHOW GLOBAL VARIABLES');
    }

    public function status(): array
    {
        return $this->nameValueRows('SHOW GLOBAL STATUS');
    }

    public function metrics(): ServerMetrics
    {
        // SHOW works even when performance_schema is off; the list is a few hundred short rows.
        $values = array_intersect_key(
            array_column($this->nameValueRows('SHOW GLOBAL STATUS'), 'value', 'name'),
            array_flip(self::METRIC_COUNTERS),
        );
        $value = fn (string $name): int => (int) ($values[$name] ?? 0);

        return new ServerMetrics(
            takenAt: microtime(true),
            uptimeSeconds: $value('Uptime'),
            questions: $value('Questions'),
            threadsConnected: $value('Threads_connected'),
            threadsRunning: $value('Threads_running'),
            maxConnections: (int) $this->connection->scalar('SELECT @@GLOBAL.max_connections'),
            bufferPoolPagesTotal: $value('Innodb_buffer_pool_pages_total'),
            bufferPoolPagesFree: $value('Innodb_buffer_pool_pages_free'),
            bytesReceived: $value('Bytes_received'),
            bytesSent: $value('Bytes_sent'),
        );
    }

    /**
     * @return list<array{name: string, value: string}>
     */
    private function nameValueRows(string $sql): array
    {
        $rows = array_map(fn (array $row): array => [
            'name' => (string) ($row['Variable_name'] ?? ''),
            'value' => (string) ($row['Value'] ?? ''),
        ], $this->connection->select($sql));

        usort($rows, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $rows;
    }
}

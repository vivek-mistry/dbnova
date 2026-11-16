<?php

namespace App\Database\DTOs;

/**
 * A snapshot of the server's cumulative counters. Rates (queries per
 * second) are the difference between two snapshots divided by the time
 * between them; the client keeps the history.
 */
final readonly class ServerMetrics
{
    public function __construct(
        public float $takenAt,
        public int $uptimeSeconds,
        public int $questions,
        public int $threadsConnected,
        public int $threadsRunning,
        public int $maxConnections,
        public int $bufferPoolPagesTotal,
        public int $bufferPoolPagesFree,
        public int $bytesReceived,
        public int $bytesSent,
    ) {}

    /**
     * @return array<string, int|float>
     */
    public function toArray(): array
    {
        return [
            'takenAt' => $this->takenAt,
            'uptimeSeconds' => $this->uptimeSeconds,
            'questions' => $this->questions,
            'threadsConnected' => $this->threadsConnected,
            'threadsRunning' => $this->threadsRunning,
            'maxConnections' => $this->maxConnections,
            'bufferPoolPagesTotal' => $this->bufferPoolPagesTotal,
            'bufferPoolPagesFree' => $this->bufferPoolPagesFree,
            'bytesReceived' => $this->bytesReceived,
            'bytesSent' => $this->bytesSent,
        ];
    }
}

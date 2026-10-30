<?php

namespace App\Database\DTOs;

final readonly class ServerInfo
{
    public function __construct(
        public string $version,
        public int $uptimeSeconds,
        public int $databaseCount,
        public int $totalSizeBytes,
    ) {}

    /**
     * @return array{version: string, uptimeSeconds: int, databaseCount: int, totalSizeBytes: int}
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'uptimeSeconds' => $this->uptimeSeconds,
            'databaseCount' => $this->databaseCount,
            'totalSizeBytes' => $this->totalSizeBytes,
        ];
    }
}

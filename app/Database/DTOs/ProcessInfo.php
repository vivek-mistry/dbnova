<?php

namespace App\Database\DTOs;

/**
 * One server thread from the process list. Which threads are listed is
 * decided by MySQL: without the PROCESS privilege, only the account's own.
 */
final readonly class ProcessInfo
{
    public function __construct(
        public int $id,
        public string $user,
        public ?string $host,
        public ?string $database,
        public string $command,
        public int $timeSeconds,
        public ?string $state,
        public ?string $info,
        public bool $isCurrentConnection,
    ) {}

    /**
     * @return array{id: int, user: string, host: string|null, database: string|null, command: string, timeSeconds: int, state: string|null, info: string|null, isCurrentConnection: bool}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'user' => $this->user,
            'host' => $this->host,
            'database' => $this->database,
            'command' => $this->command,
            'timeSeconds' => $this->timeSeconds,
            'state' => $this->state,
            'info' => $this->info,
            'isCurrentConnection' => $this->isCurrentConnection,
        ];
    }
}

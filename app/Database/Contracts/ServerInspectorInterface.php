<?php

namespace App\Database\Contracts;

use App\Database\DTOs\ProcessInfo;
use App\Database\DTOs\ServerInfo;
use App\Database\DTOs\ServerMetrics;
use App\Database\Exceptions\DatabaseOperationException;
use App\Database\Exceptions\ReadOnlyModeException;

interface ServerInspectorInterface
{
    /**
     * @throws DatabaseOperationException
     */
    public function info(): ServerInfo;

    /**
     * The threads the signed-in account may see, longest-running first.
     *
     * @return list<ProcessInfo>
     *
     * @throws DatabaseOperationException
     */
    public function processes(): array;

    /**
     * Stop a thread's running statement (KILL QUERY), or end its
     * connection (KILL). Returns false when the thread no longer exists.
     *
     * @throws DatabaseOperationException when MySQL refuses (another account's thread without the privilege, or DBNova's own connection)
     * @throws ReadOnlyModeException
     */
    public function kill(int $processId, bool $connection = false): bool;

    /**
     * Global server variables, sorted by name.
     *
     * @return list<array{name: string, value: string}>
     *
     * @throws DatabaseOperationException
     */
    public function variables(): array;

    /**
     * Global status counters, sorted by name.
     *
     * @return list<array{name: string, value: string}>
     *
     * @throws DatabaseOperationException
     */
    public function status(): array;

    /**
     * The counters behind the dashboard charts.
     *
     * @throws DatabaseOperationException
     */
    public function metrics(): ServerMetrics;

    /**
     * Whether the signed-in account holds a global (ON *.*) privilege such as "CREATE USER".
     * Privileges that come only from an active role are not seen.
     *
     * @throws DatabaseOperationException
     */
    public function hasGlobalPrivilege(string $privilege): bool;
}

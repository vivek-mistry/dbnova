<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Throwable;

/**
 * Records every change made to the managed server, whether it succeeded or not.
 */
class AuditLogger
{
    public function __construct(private Request $request) {}

    /**
     * Run an operation and record its outcome. Exceptions are recorded and rethrown.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $operation
     * @param  array<string, mixed>  $details
     * @return TResult
     *
     * @throws Throwable
     */
    public function track(string $action, ?string $database, callable $operation, array $details = [], ?string $table = null): mixed
    {
        try {
            $result = $operation();
        } catch (Throwable $exception) {
            $this->record($action, $database, $table, $details, $exception);

            throw $exception;
        }

        $this->record($action, $database, $table, $details);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public function record(string $action, ?string $database, ?string $table = null, array $details = [], ?Throwable $failure = null): AuditLog
    {
        $user = $this->request->user();

        return AuditLog::query()->create([
            'user_id' => $user instanceof User ? $user->id : null,
            'mysql_username' => $user instanceof User ? $user->username : null,
            'action' => $action,
            'database_name' => $database,
            'table_name' => $table,
            'details' => $details === [] ? null : $details,
            'succeeded' => $failure === null,
            'error' => $failure?->getMessage(),
            'ip_address' => $this->request->ip(),
        ]);
    }
}

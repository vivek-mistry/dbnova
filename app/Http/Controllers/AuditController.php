<?php

namespace App\Http\Controllers;

use App\Database\Contracts\DatabaseAdapterInterface;
use App\Database\Exceptions\DatabaseOperationException;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The audit log. Everyone sees their own entries; accounts that can create
 * MySQL users (and so already control every other account) see everyone's.
 */
class AuditController extends Controller
{
    public const int PER_PAGE = 50;

    /**
     * The global privilege that unlocks every user's entries.
     */
    public const string SEE_ALL_PRIVILEGE = 'CREATE USER';

    public function __construct(private DatabaseAdapterInterface $adapter) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'action' => ['nullable', 'string', 'max:100'],
            'user' => ['nullable', 'string', 'max:100'],
            'database' => ['nullable', 'string', 'max:64'],
            'result' => ['nullable', 'in:succeeded,failed'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $seesAll = $this->seesAll();

        $visible = AuditLog::query()->when(! $seesAll, fn (Builder $query) => $query->where('user_id', $user->id));

        $entries = (clone $visible)
            ->when($filters['action'] ?? null, fn (Builder $query, string $action) => $query->where('action', $action))
            ->when($seesAll ? ($filters['user'] ?? null) : null, fn (Builder $query, string $username) => $query->where('mysql_username', $username))
            ->when($filters['database'] ?? null, fn (Builder $query, string $database) => $query->where('database_name', $database))
            ->when($filters['result'] ?? null, fn (Builder $query, string $result) => $query->where('succeeded', $result === 'succeeded'))
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (AuditLog $entry): array => [
                'id' => $entry->id,
                'createdAt' => $entry->created_at->toIso8601String(),
                'user' => $entry->mysql_username,
                'action' => $entry->action,
                'database' => $entry->database_name,
                'table' => $entry->table_name,
                'details' => $entry->details,
                'succeeded' => $entry->succeeded,
                'error' => $entry->error,
                'ipAddress' => $entry->ip_address,
            ]);

        return Inertia::render('system/Audit', [
            'entries' => $entries,
            'filters' => [
                'action' => $filters['action'] ?? null,
                'user' => $seesAll ? ($filters['user'] ?? null) : null,
                'database' => $filters['database'] ?? null,
                'result' => $filters['result'] ?? null,
            ],
            'actions' => (clone $visible)->distinct()->orderBy('action')->pluck('action'),
            'users' => $seesAll ? (clone $visible)->whereNotNull('mysql_username')->distinct()->orderBy('mysql_username')->pluck('mysql_username') : [],
            'seesAll' => $seesAll,
        ]);
    }

    private function seesAll(): bool
    {
        try {
            return $this->adapter->server()->hasGlobalPrivilege(self::SEE_ALL_PRIVILEGE);
        } catch (DatabaseOperationException) {
            return false;
        }
    }
}

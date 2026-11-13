<?php

namespace App\Http\Controllers;

use App\Database\Contracts\DatabaseAdapterInterface;
use App\Database\DTOs\DatabaseInfo;
use App\Database\DTOs\Query\QueryRun;
use App\Database\DTOs\Query\StatementResult;
use App\Database\Exceptions\DatabaseOperationException;
use App\Database\Exceptions\DestructiveStatementsException;
use App\Database\Sql\SqlStatement;
use App\Database\Support\IdentifierQuoter;
use App\Http\Requests\Sql\RunQueryRequest;
use App\Models\QueryHistory;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The SQL editor: run SQL, cancel a running query, autocomplete schema,
 * history and saved queries.
 */
class QueryController extends Controller
{
    private const int HISTORY_ITEMS = 50;

    /**
     * How long a run can be cancelled (matches the longest request).
     */
    private const int RUN_TTL_SECONDS = 3600;

    public function __construct(
        private DatabaseAdapterInterface $adapter,
        private AuditLogger $auditLogger,
    ) {}

    /**
     * Show the editor, optionally preset to a database and a table.
     */
    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $database = $request->string('database')->toString();
        $table = $request->string('table')->toString();

        return Inertia::render('sql/Index', [
            'databases' => Inertia::defer(fn (): array => array_map(
                fn (DatabaseInfo $info): string => $info->name,
                $this->adapter->schema()->databases(),
            )),
            'database' => $database !== '' ? $database : null,
            'initialSql' => $table !== '' ? 'SELECT * FROM '.app(IdentifierQuoter::class)->quote($table).' LIMIT 100;' : '',
            'rowCap' => config()->integer('dbnova.limits.query_row_cap'),
            'timeoutSeconds' => intdiv(config()->integer('dbnova.limits.query_timeout_ms'), 1000),
            'history' => QueryHistory::query()->whereBelongsTo($user)->latest('id')->limit(self::HISTORY_ITEMS)->get()
                ->map(fn (QueryHistory $entry): array => $entry->toListItem())->all(),
            'saved' => QueryHistory::query()->whereBelongsTo($user)->saved()->orderBy('name')->get()
                ->map(fn (QueryHistory $entry): array => $entry->toListItem())->all(),
        ]);
    }

    /**
     * Run SQL and return each statement's result.
     */
    public function run(RunQueryRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $runId = $request->string('runId')->toString();
        $queryRequest = $request->toQueryRequest();

        try {
            $run = $this->adapter->queries()->run(
                $queryRequest,
                function (int $connectionId) use ($runId, $user): void {
                    Cache::put($this->runKey($runId), ['connectionId' => $connectionId, 'userId' => $user->id], self::RUN_TTL_SECONDS);
                },
            );
        } catch (DestructiveStatementsException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'destructive' => $exception->statements], 409);
        } catch (DatabaseOperationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } finally {
            Cache::forget($this->runKey($runId));
        }

        $history = $this->recordHistory($user, $queryRequest->database, $queryRequest->sql, $run);
        $this->audit($queryRequest->database, $run);

        return response()->json([...$run->toArray(), 'history' => $history->toListItem()]);
    }

    /**
     * Stop a run that is still executing.
     */
    public function cancel(Request $request): JsonResponse
    {
        $request->validate(['runId' => ['required', 'uuid']]);

        /** @var array{connectionId: int, userId: int}|null $run */
        $run = Cache::get($this->runKey($request->string('runId')->toString()));

        if ($run === null || $run['userId'] !== $this->user($request)->id) {
            return response()->json(['cancelled' => false]);
        }

        try {
            $cancelled = $this->adapter->queries()->cancel($run['connectionId']);
        } catch (DatabaseOperationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['cancelled' => $cancelled]);
    }

    /**
     * Tables and columns of a database, for autocomplete.
     */
    public function schema(Request $request): JsonResponse
    {
        $request->validate(['database' => ['required', 'string', 'max:64']]);

        return response()->json(['tables' => (object) $this->adapter->schema()->columnsByTable($request->string('database')->toString())]);
    }

    /**
     * Save a history entry under a name, or unsave it with an empty name.
     */
    public function save(Request $request, QueryHistory $history): JsonResponse
    {
        abort_unless($history->user_id === $this->user($request)->id, 404);

        $validated = $request->validate(['name' => ['nullable', 'string', 'max:100']]);
        $name = $validated['name'] ?? null;

        $history->update([
            'name' => $name === null || $name === '' ? null : $name,
            'saved_at' => $name === null || $name === '' ? null : now(),
        ]);

        return response()->json($history->toListItem());
    }

    private function recordHistory(User $user, ?string $database, string $sql, QueryRun $run): QueryHistory
    {
        $history = QueryHistory::query()->create([
            'user_id' => $user->id,
            'database_name' => $database,
            'sql' => $sql,
            'statement_count' => count($run->statements),
            'status' => $run->succeeded() ? 'ok' : 'error',
            'error' => $run->firstError()?->error,
            'duration_ms' => $run->durationMs(),
        ]);

        $keep = QueryHistory::query()->whereBelongsTo($user)->whereNull('saved_at')
            ->latest('id')->skip(QueryHistory::KEEP_PER_USER)->take(1)->value('id');

        if ($keep !== null) {
            QueryHistory::query()->whereBelongsTo($user)->whereNull('saved_at')->where('id', '<=', $keep)->delete();
        }

        return $history;
    }

    /**
     * Audit runs that could change data. Only keywords are recorded, never the SQL.
     */
    private function audit(?string $database, QueryRun $run): void
    {
        $writes = array_values(array_filter(
            $run->statements,
            fn (StatementResult $statement): bool => $statement->status !== 'skipped' && ! SqlStatement::isReadOnlyKeyword($statement->keyword),
        ));

        if ($writes === []) {
            return;
        }

        $error = $run->firstError();

        $this->auditLogger->record(
            'sql.execute',
            $database,
            null,
            [
                'statements' => count($run->statements),
                'keywords' => array_values(array_unique(array_map(fn (StatementResult $statement): string => $statement->keyword, $writes))),
            ],
            $error === null ? null : new DatabaseOperationException((string) $error->error),
        );
    }

    private function runKey(string $runId): string
    {
        return 'dbnova:sql-run:'.$runId;
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}

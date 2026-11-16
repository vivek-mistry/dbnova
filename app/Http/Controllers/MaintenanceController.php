<?php

namespace App\Http\Controllers;

use App\Database\Contracts\DatabaseAdapterInterface;
use App\Database\DTOs\Maintenance\MaintenanceOperation;
use App\Database\DTOs\Maintenance\MaintenanceResult;
use App\Database\Exceptions\DatabaseOperationException;
use App\Database\Exceptions\ReadOnlyModeException;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MaintenanceController extends Controller
{
    /**
     * How many tables one request may name. MySQL runs them one after another.
     */
    public const int MAX_TABLES = 200;

    public function __construct(private DatabaseAdapterInterface $adapter) {}

    /**
     * Run OPTIMIZE, ANALYZE, CHECK or REPAIR TABLE on one or more tables and return MySQL's result rows.
     */
    public function __invoke(Request $request, string $database, AuditLogger $auditLogger): JsonResponse
    {
        $validated = $request->validate([
            'operation' => ['required', Rule::enum(MaintenanceOperation::class)],
            'tables' => ['required', 'array', 'min:1', 'max:'.self::MAX_TABLES],
            'tables.*' => ['required', 'string', 'max:64', 'distinct'],
        ]);

        $operation = MaintenanceOperation::from($validated['operation']);
        /** @var list<string> $tables */
        $tables = array_values($validated['tables']);

        // OPTIMIZE rebuilds each table: a big one can take far longer than a normal request.
        set_time_limit(0);

        $table = count($tables) === 1 ? $tables[0] : null;
        $details = ['tables' => $tables];

        try {
            $results = $this->adapter->tables()->maintain($database, $tables, $operation);
        } catch (ReadOnlyModeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 403);
        } catch (DatabaseOperationException $exception) {
            if ($operation->changesServer()) {
                $auditLogger->record('table.'.$operation->value, $database, $table, $details, $exception);
            }

            return response()->json(['message' => $exception->getMessage()], 422);
        }

        // CHECK only reads, so it is not a change to audit.
        if ($operation->changesServer()) {
            $problems = count(array_filter($results, fn (MaintenanceResult $result): bool => $result->type === 'error'));
            $auditLogger->record('table.'.$operation->value, $database, $table, [...$details, 'errors' => $problems]);
        }

        return response()->json([
            'operation' => $operation->value,
            'results' => array_map(fn (MaintenanceResult $result): array => $result->toArray(), $results),
        ]);
    }
}

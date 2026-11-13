<?php

namespace App\Http\Controllers;

use App\Database\Contracts\DatabaseAdapterInterface;
use App\Database\DTOs\Schema\CreateTable;
use App\Database\DTOs\Schema\DdlResult;
use App\Database\DTOs\Schema\DropTable;
use App\Database\DTOs\Schema\RenameTable;
use App\Database\DTOs\Schema\SchemaChange;
use App\Database\DTOs\TableRef;
use App\Database\Exceptions\DatabaseOperationException;
use App\Database\Exceptions\LockingDdlRequiresConfirmationException;
use App\Http\Requests\Schema\SchemaChangeRequest;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;

/**
 * JSON endpoints for schema changes: preview the SQL, then apply it.
 */
class SchemaChangeController extends Controller
{
    public function __construct(
        private DatabaseAdapterInterface $adapter,
        private AuditLogger $auditLogger,
    ) {}

    /**
     * The SQL a change would run. Runs nothing.
     */
    public function preview(SchemaChangeRequest $request, string $database): JsonResponse
    {
        try {
            $plan = $this->adapter->tables()->preview(new TableRef($database, $request->tableName()), $request->toChange());
        } catch (DatabaseOperationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($plan->toArray());
    }

    /**
     * Apply a change and say where the user should go next.
     */
    public function apply(SchemaChangeRequest $request, string $database): JsonResponse
    {
        $table = new TableRef($database, $request->tableName());
        $change = $request->toChange();

        try {
            /** @var DdlResult $result */
            $result = $this->auditLogger->track(
                $change->action(),
                $database,
                fn (): DdlResult => $this->adapter->tables()->apply($table, $change, $request->allowsLocking()),
                $change->auditDetails(),
                $table->table,
            );
        } catch (LockingDdlRequiresConfirmationException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'needsLockingConfirmation' => true,
                'approximateRows' => $exception->approximateRows,
            ], 409);
        } catch (DatabaseOperationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => $this->successMessage($change, $result),
            'algorithm' => $result->algorithm,
            'redirect' => $this->redirectAfter($table, $change),
        ]);
    }

    private function successMessage(SchemaChange $change, DdlResult $result): string
    {
        $message = 'Schema change applied.';

        return match ($result->algorithm) {
            'INSTANT' => $message.' MySQL changed only the table metadata.',
            'INPLACE' => $message.' MySQL kept the table writable while it ran.',
            'COPY' => $message.' MySQL copied the table to apply it.',
            default => $change instanceof CreateTable ? 'Table created.' : $message,
        };
    }

    private function redirectAfter(TableRef $table, SchemaChange $change): ?string
    {
        return match (true) {
            $change instanceof DropTable => route('databases.show', $table->database, absolute: false),
            $change instanceof RenameTable => route('tables.structure', ['database' => $table->database, 'table' => $change->newName], absolute: false),
            $change instanceof CreateTable => route('tables.structure', ['database' => $table->database, 'table' => $table->table], absolute: false),
            default => null,
        };
    }
}

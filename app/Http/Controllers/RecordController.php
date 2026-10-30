<?php

namespace App\Http\Controllers;

use App\Database\Contracts\DatabaseAdapterInterface;
use App\Database\DTOs\Page;
use App\Database\DTOs\TableRef;
use App\Database\Exceptions\DatabaseOperationException;
use App\Http\Requests\Tables\DeleteRecordsRequest;
use App\Http\Requests\Tables\StoreRecordRequest;
use App\Http\Requests\Tables\UpdateRecordRequest;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;

/**
 * JSON endpoints the data grid uses to change rows. MySQL rejections
 * (constraints, permissions, read-only mode) come back as 422 with the reason.
 * Audit entries record column names and row counts, never values.
 */
class RecordController extends Controller
{
    public function __construct(
        private DatabaseAdapterInterface $adapter,
        private AuditLogger $auditLogger,
    ) {}

    /**
     * Insert a row.
     */
    public function store(StoreRecordRequest $request, string $database, string $table): JsonResponse
    {
        $values = $request->columnValues();

        return $this->attempt(function () use ($database, $table, $values): JsonResponse {
            $this->auditLogger->track(
                'record.insert',
                $database,
                fn () => $this->adapter->records()->insert(new TableRef($database, $table), $values),
                ['columns' => array_keys($values)],
                $table,
            );

            return response()->json(['message' => 'Row inserted.'], 201);
        });
    }

    /**
     * Update one row and return it as it is now stored.
     */
    public function update(UpdateRecordRequest $request, string $database, string $table): JsonResponse
    {
        $tableRef = new TableRef($database, $table);
        $values = $request->columnValues();

        return $this->attempt(function () use ($request, $database, $table, $tableRef, $values): JsonResponse {
            $newKey = $this->auditLogger->track(
                'record.update',
                $database,
                fn (): array => $this->adapter->records()->update($tableRef, $request->keyValues(), $values),
                ['columns' => array_keys($values)],
                $table,
            );

            return response()->json([
                'key' => Page::encodeCursor($newKey),
                'row' => $this->adapter->browser()->row($tableRef, $newKey),
            ]);
        });
    }

    /**
     * Delete rows by key.
     */
    public function destroy(DeleteRecordsRequest $request, string $database, string $table): JsonResponse
    {
        $keys = $request->keys();

        return $this->attempt(function () use ($database, $table, $keys): JsonResponse {
            $deleted = $this->auditLogger->track(
                'record.delete',
                $database,
                fn (): int => $this->adapter->records()->delete(new TableRef($database, $table), $keys),
                ['requested' => count($keys)],
                $table,
            );

            return response()->json(['deleted' => $deleted]);
        });
    }

    /**
     * @param  callable(): JsonResponse  $operation
     */
    private function attempt(callable $operation): JsonResponse
    {
        try {
            return $operation();
        } catch (DatabaseOperationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }
}

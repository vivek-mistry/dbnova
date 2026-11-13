<?php

namespace App\Http\Controllers;

use App\Database\Contracts\DatabaseAdapterInterface;
use App\Database\DTOs\DatabaseInfo;
use App\Database\DTOs\TableInfo;
use App\Database\Exceptions\DatabaseOperationException;
use App\Database\Exceptions\ReadOnlyModeException;
use App\Database\Support\ReadOnlyGuard;
use App\Enums\ImportStatus;
use App\Enums\UploadStatus;
use App\Http\Requests\Imports\ResetImportTargetRequest;
use App\Jobs\ImportDatabaseJob;
use App\Jobs\PreflightImportJob;
use App\Models\ImportJob;
use App\Models\Upload;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\MySQLCredentialStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The import wizard: choose a file and a target database, check the file
 * (preflight, queued), then import (Phase 6b).
 */
class ImportController extends Controller
{
    private const int HISTORY_ITEMS = 20;

    public function __construct(private DatabaseAdapterInterface $adapter) {}

    public function index(Request $request): Response
    {
        $user = $this->user($request);

        return Inertia::render('import-export/Import', [
            'databases' => Inertia::defer(fn (): array => array_values(array_map(
                fn (DatabaseInfo $info): string => $info->name,
                array_filter($this->adapter->schema()->databases(), fn (DatabaseInfo $info): bool => ! $info->isSystem),
            ))),
            'database' => $request->string('database')->toString() ?: null,
            'imports' => ImportJob::query()->whereBelongsTo($user)->with('upload')->latest()->limit(self::HISTORY_ITEMS)->get()
                ->map(fn (ImportJob $import): array => $import->toStatus())->all(),
            'chunkBytes' => config()->integer('dbnova.limits.upload_chunk_bytes'),
            'maxUploadBytes' => config()->integer('dbnova.limits.max_upload_bytes'),
        ]);
    }

    /**
     * Create an import for an uploaded file and queue its preflight check.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $validated = $request->validate([
            'uploadId' => ['required', 'uuid', Rule::exists('uploads', 'id')->where('user_id', $user->id)->where('status', UploadStatus::Uploaded->value)],
            'database' => ['required', 'string', 'max:64'],
        ], [
            'uploadId.exists' => 'The file has not finished uploading.',
        ]);

        $target = $this->adapter->schema()->database($validated['database']);
        $problem = match (true) {
            $target === null => 'That database does not exist.',
            $target->isSystem => 'Imports into MySQL system databases are not allowed.',
            default => null,
        };

        if ($problem !== null) {
            return response()->json(['message' => $problem, 'errors' => ['database' => [$problem]]], 422);
        }

        $upload = Upload::query()->whereKey($validated['uploadId'])->firstOrFail();

        $import = ImportJob::query()->create([
            'user_id' => $user->id,
            'upload_id' => $upload->id,
            'database_name' => $validated['database'],
            'status' => ImportStatus::Pending,
            'bytes_total' => $upload->size,
        ]);

        PreflightImportJob::dispatch($import->id);

        return response()->json($this->status($import->load('upload')), 201);
    }

    /**
     * Polled while the import is active.
     */
    public function show(Request $request, ImportJob $import): JsonResponse
    {
        $this->authorizeOwner($request, $import);

        return response()->json($this->status($import->load('upload')));
    }

    /**
     * Queue a checked import. The worker has no session, so the signed-in
     * MySQL credentials are stored on the import (encrypted) until it ends.
     */
    public function start(Request $request, ImportJob $import, MySQLCredentialStore $credentialStore, AuditLogger $auditLogger, ReadOnlyGuard $readOnlyGuard): JsonResponse
    {
        $this->authorizeOwner($request, $import);
        $validated = $request->validate(['speedMode' => ['boolean'], 'trustCompound' => ['boolean']]);
        $trustCompound = (bool) ($validated['trustCompound'] ?? false);

        if ((int) ($import->preflight['compoundProgramCount'] ?? 0) > 0 && ! $trustCompound) {
            return response()->json([
                'message' => 'This dump has routines, triggers or events that DBNova cannot check. Confirm that you trust it to start the import.',
                'errors' => ['trustCompound' => ['Confirm that you trust this dump.']],
            ], 422);
        }

        if ($readOnlyGuard->isReadOnly()) {
            return response()->json(['message' => (new ReadOnlyModeException)->getMessage()], 403);
        }

        $credentials = $credentialStore->get();

        if ($credentials === null) {
            return response()->json(['message' => 'Your session has expired. Sign in again.'], 401);
        }

        $speedMode = (bool) ($validated['speedMode'] ?? false);

        $started = ImportJob::query()->whereKey($import->id)->where('status', ImportStatus::Ready)->update([
            'status' => ImportStatus::Queued,
            'credentials' => Crypt::encryptString(json_encode($credentials, JSON_THROW_ON_ERROR)),
            'options' => json_encode(['speedMode' => $speedMode, 'trustCompound' => $trustCompound], JSON_THROW_ON_ERROR),
            'bytes_scanned' => 0,
            'cancel_requested' => false,
        ]);

        if ($started === 0) {
            return response()->json(['message' => 'Only a checked import that has not started yet can be started.'], 409);
        }

        ImportDatabaseJob::dispatch($import->id);
        $auditLogger->record('import.start', $import->database_name, details: ['file' => $import->upload?->original_name, 'speedMode' => $speedMode]);

        return response()->json($this->status($import->refresh()->load('upload')));
    }

    /**
     * After a failed, cancelled or interrupted import: drop the target
     * database and create it again, empty, with the same collation.
     */
    public function resetTarget(ResetImportTargetRequest $request, ImportJob $import, AuditLogger $auditLogger): JsonResponse
    {
        $this->authorizeOwner($request, $import);

        if (! in_array($import->status, [ImportStatus::Failed, ImportStatus::Cancelled, ImportStatus::Interrupted], true)) {
            return response()->json(['message' => 'Only the target of a failed, cancelled or interrupted import can be reset here.'], 409);
        }

        $schema = $this->adapter->schema();
        $target = $schema->database($import->database_name);

        if ($target === null || $target->isSystem) {
            return response()->json(['message' => 'That database does not exist.'], 422);
        }

        $otherImport = ImportJob::query()->where('database_name', $target->name)
            ->whereIn('status', [ImportStatus::Queued, ImportStatus::Importing])->exists();

        if ($otherImport) {
            return response()->json(['message' => 'Another import into this database is queued or running. Wait for it to finish or cancel it first.'], 409);
        }

        try {
            // A worker that was killed can leave its mysql client running; dropping now would race with it.
            if ($schema->busySessionCount($target->name) > 0) {
                return response()->json(['message' => 'MySQL is still running statements in this database (perhaps the stopped import\'s client). Wait for them to finish, or stop them under Processes, then try again.'], 409);
            }

            $auditLogger->track('import.reset-target', $import->database_name, fn () => $schema->dropDatabase($target->name));
        } catch (DatabaseOperationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        try {
            $schema->createDatabase($target->name, $target->collation);
        } catch (DatabaseOperationException $exception) {
            return response()->json(['message' => 'The database was dropped, but creating it again failed: '.$exception->getMessage().' Create it under Databases.'], 422);
        }

        $import->update(['options' => [...($import->options ?? []), 'targetReset' => true]]);

        return response()->json($this->status($import->load('upload')));
    }

    /**
     * Stop a pending or running check, or dismiss a checked import.
     */
    public function cancel(Request $request, ImportJob $import): JsonResponse
    {
        $this->authorizeOwner($request, $import);

        // Not picked up by a worker yet: cancelled at once (the worker's claim then fails).
        ImportJob::query()->whereKey($import->id)
            ->whereIn('status', [ImportStatus::Pending, ImportStatus::Ready, ImportStatus::Queued])
            ->update(['status' => ImportStatus::Cancelled, 'credentials' => null, 'finished_at' => now()]);

        // Running: the worker checks this flag and stops the mysql client.
        ImportJob::query()->whereKey($import->id)
            ->whereIn('status', [ImportStatus::Preflight, ImportStatus::Importing])
            ->update(['cancel_requested' => true]);

        return response()->json($this->status($import->refresh()->load('upload')));
    }

    /**
     * The import's status, plus the tables that already exist in the target
     * once the check has passed (a conflict warning).
     *
     * @return array<string, mixed>
     */
    private function status(ImportJob $import): array
    {
        $status = $import->toStatus();
        $status['conflicts'] = [];

        if ($import->status === ImportStatus::Ready) {
            /** @var list<string> $tables */
            $tables = $import->preflight['tables'] ?? [];

            if ($tables !== []) {
                $existing = array_map(fn (TableInfo $table): string => $table->name, $this->adapter->schema()->tables($import->database_name));
                $status['conflicts'] = array_values(array_intersect($tables, $existing));
            }
        }

        return $status;
    }

    private function authorizeOwner(Request $request, ImportJob $import): void
    {
        abort_unless($import->user_id === $this->user($request)->id, 404);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}

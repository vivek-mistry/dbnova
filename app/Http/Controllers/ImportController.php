<?php

namespace App\Http\Controllers;

use App\Database\Contracts\DatabaseAdapterInterface;
use App\Database\DTOs\DatabaseInfo;
use App\Database\DTOs\TableInfo;
use App\Enums\ImportStatus;
use App\Enums\UploadStatus;
use App\Jobs\PreflightImportJob;
use App\Models\ImportJob;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
     * Stop a pending or running check, or dismiss a checked import.
     */
    public function cancel(Request $request, ImportJob $import): JsonResponse
    {
        $this->authorizeOwner($request, $import);

        ImportJob::query()->whereKey($import->id)
            ->whereIn('status', [ImportStatus::Pending, ImportStatus::Ready])
            ->update(['status' => ImportStatus::Cancelled, 'finished_at' => now()]);

        ImportJob::query()->whereKey($import->id)
            ->whereIn('status', [ImportStatus::Preflight, ImportStatus::Queued, ImportStatus::Importing])
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

<?php

namespace App\Http\Controllers;

use App\Database\Contracts\DatabaseAdapterInterface;
use App\Database\DTOs\DatabaseInfo;
use App\Database\DTOs\TableInfo;
use App\Enums\ExportFormat;
use App\Enums\ExportStatus;
use App\Jobs\ExportDatabaseJob;
use App\Models\ExportJob;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\MySQLCredentialStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Database and table exports to .sql / .sql.gz (Phase 7a). The dump runs
 * in a queued job; the file is downloaded through a short-lived signed
 * link. Exports only read, so read-only mode allows them.
 */
class ExportController extends Controller
{
    private const int HISTORY_ITEMS = 20;

    /** Unfinished exports per user. */
    private const int MAX_ACTIVE_EXPORTS = 3;

    /** Virtual schemas mysqldump cannot dump meaningfully. */
    private const array NOT_EXPORTABLE = ['information_schema', 'performance_schema'];

    public function __construct(private DatabaseAdapterInterface $adapter) {}

    public function index(Request $request): Response
    {
        $user = $this->user($request);

        return Inertia::render('import-export/Export', [
            'databases' => Inertia::defer(fn (): array => array_values(array_map(
                fn (DatabaseInfo $info): array => ['name' => $info->name, 'sizeBytes' => $info->sizeBytes, 'tableCount' => $info->tableCount, 'isSystem' => $info->isSystem],
                array_filter($this->adapter->schema()->databases(), fn (DatabaseInfo $info): bool => ! in_array($info->name, self::NOT_EXPORTABLE, true)),
            ))),
            'database' => $request->string('database')->toString() ?: null,
            'exports' => ExportJob::query()->whereBelongsTo($user)->latest()->limit(self::HISTORY_ITEMS)->get()
                ->map(fn (ExportJob $export): array => $export->toStatus())->all(),
        ]);
    }

    /**
     * The tables to choose from, with their catalog sizes.
     */
    public function tables(string $database): JsonResponse
    {
        abort_if(in_array($database, self::NOT_EXPORTABLE, true) || $this->adapter->schema()->database($database) === null, 404);

        return response()->json(array_map(fn (TableInfo $table): array => [
            'name' => $table->name,
            'type' => $table->type,
            'sizeBytes' => $table->sizeBytes,
            'approximateRows' => $table->approximateRows,
        ], $this->adapter->schema()->tables($database)));
    }

    /**
     * Queue an export. The worker has no session, so the signed-in MySQL
     * credentials are stored on the export (encrypted) until it starts.
     */
    public function store(Request $request, MySQLCredentialStore $credentialStore, AuditLogger $auditLogger): JsonResponse
    {
        $user = $this->user($request);

        $validated = $request->validate([
            'database' => ['required', 'string', 'max:64'],
            'tables' => ['nullable', 'array', 'max:10000'],
            'tables.*' => ['string', 'max:64', 'distinct'],
            'format' => ['required', Rule::enum(ExportFormat::class)],
            'content' => ['required', Rule::in(['both', 'structure', 'data'])],
            'includePrograms' => ['boolean'],
        ]);

        $database = $validated['database'];
        /** @var list<string> $tables */
        $tables = array_values($validated['tables'] ?? []);

        if (in_array($database, self::NOT_EXPORTABLE, true) || $this->adapter->schema()->database($database) === null) {
            return $this->invalid('database', 'That database does not exist or cannot be exported.');
        }

        if ($tables !== []) {
            $existing = array_map(fn (TableInfo $table): string => $table->name, $this->adapter->schema()->tables($database));
            $missing = array_diff($tables, $existing);

            if ($missing !== []) {
                return $this->invalid('tables', 'These tables do not exist: '.implode(', ', array_slice($missing, 0, 10)).'.');
            }
        }

        $active = ExportJob::query()->whereBelongsTo($user)->whereIn('status', [ExportStatus::Queued, ExportStatus::Exporting])->count();

        if ($active >= self::MAX_ACTIVE_EXPORTS) {
            return response()->json(['message' => 'You already have '.self::MAX_ACTIVE_EXPORTS.' exports running. Wait for one to finish or cancel it.'], 429);
        }

        $estimate = $this->adapter->exporter()->estimateBytes($database, $tables);
        $directory = config()->string('dbnova.paths.exports');
        File::ensureDirectoryExists($directory, 0700);
        $free = @disk_free_space($directory);
        $minimumFree = config()->integer('dbnova.limits.min_free_disk_bytes');

        if ($free !== false && $free < $estimate + $minimumFree) {
            return $this->invalid('database', sprintf(
                'Not enough free disk space: the export may need about %s, and %s must stay free.',
                self::humanBytes($estimate),
                self::humanBytes($minimumFree),
            ));
        }

        $credentials = $credentialStore->get();

        if ($credentials === null) {
            return response()->json(['message' => 'Your session has expired. Sign in again.'], 401);
        }

        $format = ExportFormat::from($validated['format']);
        $includePrograms = (bool) ($validated['includePrograms'] ?? true);

        $export = ExportJob::query()->create([
            'user_id' => $user->id,
            'database_name' => $database,
            'tables' => $tables === [] ? null : $tables,
            'format' => $format,
            'options' => ['content' => $validated['content'], 'includePrograms' => $includePrograms],
            'status' => ExportStatus::Queued,
            'file_name' => self::fileName($database, $tables, $format),
            'estimated_bytes' => $estimate,
            'credentials' => $credentials,
        ]);

        ExportDatabaseJob::dispatch($export->id);
        $auditLogger->record('export.start', $database, details: [
            'tables' => $tables === [] ? 'all' : count($tables),
            'format' => $format->value,
            'content' => $validated['content'],
        ]);

        return response()->json($export->refresh()->toStatus(), 201);
    }

    /**
     * Polled while the export is active.
     */
    public function show(Request $request, ExportJob $export): JsonResponse
    {
        $this->authorizeOwner($request, $export);

        return response()->json($export->toStatus());
    }

    public function cancel(Request $request, ExportJob $export): JsonResponse
    {
        $this->authorizeOwner($request, $export);

        // Not picked up by a worker yet: cancelled at once (the worker's claim then fails).
        ExportJob::query()->whereKey($export->id)->where('status', ExportStatus::Queued)
            ->update(['status' => ExportStatus::Cancelled, 'credentials' => null, 'finished_at' => now()]);

        // Running: the worker checks this flag, stops mysqldump and deletes the partial file.
        ExportJob::query()->whereKey($export->id)->where('status', ExportStatus::Exporting)
            ->update(['cancel_requested' => true]);

        return response()->json($export->refresh()->toStatus());
    }

    /**
     * Delete a finished export's file now instead of waiting for it to expire.
     */
    public function destroy(Request $request, ExportJob $export): JsonResponse
    {
        $this->authorizeOwner($request, $export);

        if ($export->status->isActive()) {
            return response()->json(['message' => 'Cancel the export first.'], 409);
        }

        File::delete($export->path());

        if ($export->status === ExportStatus::Completed) {
            $export->update(['status' => ExportStatus::Expired, 'expires_at' => now()]);
        }

        return response()->json($export->refresh()->toStatus());
    }

    /**
     * Streams the file from disk (never loaded into memory). The route is
     * signed and expires; the export must also belong to the signed-in user.
     */
    public function download(Request $request, ExportJob $export, AuditLogger $auditLogger): BinaryFileResponse
    {
        $this->authorizeOwner($request, $export);
        abort_unless($export->isDownloadable(), 410, 'This export has expired. Export again.');

        $auditLogger->record('export.download', $export->database_name, details: ['format' => $export->format->value, 'bytes' => $export->file_bytes]);

        return response()->download($export->path(), $export->file_name, [
            'Content-Type' => $export->format->isCompressed() ? 'application/gzip' : 'application/sql',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * shop.sql, shop-orders.sql.gz, shop-3-tables.sql; only safe characters.
     *
     * @param  list<string>  $tables
     */
    private static function fileName(string $database, array $tables, ExportFormat $format): string
    {
        $suffix = match (count($tables)) {
            0 => '',
            1 => '-'.$tables[0],
            default => '-'.count($tables).'-tables',
        };

        $name = preg_replace('/[^A-Za-z0-9_.-]+/', '_', $database.$suffix) ?? 'export';
        $name = trim(substr($name, 0, 120), '._') ?: 'export';

        return $name.'-'.now()->format('Ymd-His').'.'.$format->extension();
    }

    private static function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = $bytes > 0 ? min((int) floor(log($bytes, 1024)), count($units) - 1) : 0;

        return round($bytes / 1024 ** $power, 1).' '.$units[$power];
    }

    private function invalid(string $field, string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'errors' => [$field => [$message]]], 422);
    }

    private function authorizeOwner(Request $request, ExportJob $export): void
    {
        abort_unless($export->user_id === $this->user($request)->id, 404);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}

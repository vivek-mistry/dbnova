<?php

namespace App\Jobs;

use App\Database\Contracts\DatabaseAdapterInterface;
use App\Database\DTOs\Import\ImportRequest;
use App\Database\DTOs\Import\ImportResult;
use App\Database\Exceptions\DatabaseOperationException;
use App\Database\Exceptions\ReadOnlyModeException;
use App\Database\Import\ImportCancelledException;
use App\Database\Import\SourceException;
use App\Database\Import\SourceStream;
use App\Enums\ImportStatus;
use App\Enums\UploadStatus;
use App\Models\AuditLog;
use App\Models\ImportJob;
use App\Services\Import\UploadStore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Streams a checked dump into its target database through the mysql
 * client. The payload is the import id only; the MySQL credentials are
 * read (encrypted) from the import and wiped when it ends.
 *
 * Progress is honest: bytes sent to MySQL, never "% imported". A failed,
 * cancelled or interrupted import is partial; nothing is rolled back.
 */
class ImportDatabaseJob implements ShouldQueue
{
    use Queueable;

    /** Never retried: re-running a partial import would duplicate data. */
    public int $tries = 1;

    /** Seconds; below the queue's retry_after (25 h) so the job is never run twice. */
    public int $timeout = 86400;

    public bool $failOnTimeout = true;

    /** Progress and the heartbeat are written at most this often (seconds). */
    private const float PROGRESS_INTERVAL = 2.0;

    public function __construct(public string $importJobId)
    {
        $this->onQueue('imports');
    }

    public function handle(DatabaseAdapterInterface $adapter, UploadStore $uploadStore): void
    {
        $claimed = ImportJob::query()->whereKey($this->importJobId)->where('status', ImportStatus::Queued)->update([
            'status' => ImportStatus::Importing,
            'started_at' => now(),
            'heartbeat_at' => now(),
            'worker_pid' => getmypid() ?: null,
        ]);

        if ($claimed === 0) {
            return; // Cancelled before it started, or already handled.
        }

        $import = ImportJob::query()->with('upload')->findOrFail($this->importJobId);
        $upload = $import->upload;
        $credentials = $import->credentials;

        // Needed only to start the client: don't keep the password at rest while the import runs.
        ImportJob::query()->whereKey($this->importJobId)->update(['credentials' => null]);

        if ($upload === null || $upload->status !== UploadStatus::Uploaded || $credentials === null) {
            $this->finish(ImportStatus::Failed, 'The uploaded file or the saved sign-in is no longer available. Start the import again.');

            return;
        }

        try {
            $source = SourceStream::open($upload->path(), $upload->format);
        } catch (SourceException $exception) {
            $this->finish(ImportStatus::Failed, $exception->getMessage());

            return;
        }

        $lastWrite = 0.0;

        try {
            $result = $adapter->importer()->import(
                new ImportRequest(
                    $import->database_name,
                    $credentials,
                    is_string($import->preflight['sourceDatabase'] ?? null) ? $import->preflight['sourceDatabase'] : null,
                    (bool) ($import->options['speedMode'] ?? false),
                    (bool) ($import->options['trustCompound'] ?? false),
                ),
                $source,
                function (int $position, int $bytesSent, int $statements) use (&$lastWrite): void {
                    if (microtime(true) - $lastWrite < self::PROGRESS_INTERVAL) {
                        return;
                    }

                    $lastWrite = microtime(true);
                    ImportJob::query()->whereKey($this->importJobId)->update([
                        'bytes_scanned' => $position,
                        'bytes_streamed' => $bytesSent,
                        'statements_seen' => $statements,
                        'heartbeat_at' => now(),
                    ]);

                    if (ImportJob::query()->whereKey($this->importJobId)->value('cancel_requested')) {
                        throw new ImportCancelledException;
                    }
                },
            );
        } catch (ImportCancelledException) {
            $this->finish(ImportStatus::Cancelled, null);

            return;
        } catch (DatabaseOperationException|ReadOnlyModeException $exception) {
            $this->finish(ImportStatus::Failed, $exception->getMessage());

            return;
        } finally {
            $source->close();
        }

        $this->finish($result->succeeded ? ImportStatus::Completed : ImportStatus::Failed, $result->error, $result);

        if ($result->succeeded) {
            // The file is no longer needed; failed imports keep it for a retry (retention.failed_import_hours).
            $uploadStore->deleteFiles($upload);
            $upload->update(['status' => UploadStatus::Expired]);
        }
    }

    /**
     * The worker crashed or timed out: say so instead of leaving "importing" forever.
     */
    public function failed(?Throwable $exception): void
    {
        $updated = ImportJob::query()->whereKey($this->importJobId)
            ->whereIn('status', [ImportStatus::Queued, ImportStatus::Importing])
            ->update([
                'status' => ImportStatus::Failed,
                'last_error' => 'The import stopped unexpectedly (the worker crashed or timed out). Data may be partially imported.',
                'credentials' => null,
                'finished_at' => now(),
            ]);

        if ($updated > 0) {
            $this->audit(ImportStatus::Failed);
        }
    }

    private function finish(ImportStatus $status, ?string $error, ?ImportResult $result = null): void
    {
        $attributes = [
            'status' => $status,
            'last_error' => $error,
            'error_count' => $status === ImportStatus::Failed ? 1 : 0,
            'credentials' => null,
            'heartbeat_at' => now(),
            'finished_at' => now(),
        ];

        if ($result !== null) {
            $attributes['bytes_streamed'] = $result->bytesSent;
            $attributes['statements_seen'] = $result->statementsSent;
            $attributes['error_line'] = $result->errorLine;
            $attributes['bytes_scanned'] = ImportJob::query()->whereKey($this->importJobId)->value('bytes_total');
            $attributes['options'] = [
                ...(ImportJob::query()->findOrFail($this->importJobId)->options ?? []),
                'skippedStatements' => $result->skippedStatements,
                'warningCount' => $result->warningCount,
                'warnings' => $result->warnings,
            ];
        }

        ImportJob::query()->whereKey($this->importJobId)->where('status', ImportStatus::Importing)->update($attributes);
        $this->audit($status, $error);
    }

    /**
     * Imports change data, so they are audited (names and counts only, never SQL).
     */
    private function audit(ImportStatus $status, ?string $error = null): void
    {
        $import = ImportJob::query()->with('user', 'upload')->find($this->importJobId);

        if ($import === null) {
            return;
        }

        AuditLog::query()->create([
            'user_id' => $import->user_id,
            'mysql_username' => $import->user?->username,
            'action' => 'import.'.$status->value,
            'database_name' => $import->database_name,
            'details' => [
                'file' => $import->upload?->original_name,
                'bytesSent' => $import->bytes_streamed,
                'statements' => $import->statements_seen,
            ],
            'succeeded' => $status === ImportStatus::Completed,
            'error' => $error === null ? null : mb_strimwidth($error, 0, 500, '…'),
        ]);
    }
}

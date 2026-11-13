<?php

namespace App\Jobs;

use App\Database\Contracts\DatabaseAdapterInterface;
use App\Database\DTOs\Export\ExportRequest;
use App\Database\Exceptions\DatabaseOperationException;
use App\Database\Export\ExportCancelledException;
use App\Enums\ExportStatus;
use App\Models\AuditLog;
use App\Models\ExportJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Dumps a database to a file with mysqldump. The payload is the export id
 * only; the MySQL credentials are read (encrypted) from the export and
 * wiped as soon as the dump tool has them.
 */
class ExportDatabaseJob implements ShouldQueue
{
    use Queueable;

    /** A half-written dump is useless; the user starts a new export instead. */
    public int $tries = 1;

    /** Seconds; below the queue's retry_after (25 h) so the job is never run twice. */
    public int $timeout = 86400;

    public bool $failOnTimeout = true;

    /** Progress and the heartbeat are written at most this often (seconds). */
    private const float PROGRESS_INTERVAL = 2.0;

    public function __construct(public string $exportJobId)
    {
        $this->onQueue('exports');
    }

    public function handle(DatabaseAdapterInterface $adapter): void
    {
        $claimed = ExportJob::query()->whereKey($this->exportJobId)->where('status', ExportStatus::Queued)->update([
            'status' => ExportStatus::Exporting,
            'started_at' => now(),
            'heartbeat_at' => now(),
            'worker_pid' => getmypid() ?: null,
        ]);

        if ($claimed === 0) {
            return; // Cancelled before it started, or already handled.
        }

        $export = ExportJob::query()->whereKey($this->exportJobId)->firstOrFail();
        $credentials = $export->credentials;

        // Needed only to start the dump tool: don't keep the password at rest while it runs.
        ExportJob::query()->whereKey($this->exportJobId)->update(['credentials' => null]);

        if ($credentials === null) {
            $this->finish(ExportStatus::Failed, 'The saved sign-in is no longer available. Start the export again.');

            return;
        }

        $minimumFreeBytes = config()->integer('dbnova.limits.min_free_disk_bytes');
        $directory = config()->string('dbnova.paths.exports');
        $lastWrite = 0.0;

        try {
            $result = $adapter->exporter()->export(
                new ExportRequest(
                    $export->database_name,
                    $credentials,
                    $export->path(),
                    $export->format,
                    $export->tables ?? [],
                    in_array($export->options['content'] ?? null, ['structure', 'data'], true) ? $export->options['content'] : 'both',
                    (bool) ($export->options['includePrograms'] ?? true),
                ),
                function (int $bytesWritten, int $fileBytes) use (&$lastWrite, $directory, $minimumFreeBytes): void {
                    if (microtime(true) - $lastWrite < self::PROGRESS_INTERVAL) {
                        return;
                    }

                    $lastWrite = microtime(true);
                    ExportJob::query()->whereKey($this->exportJobId)->update([
                        'bytes_written' => $bytesWritten,
                        'file_bytes' => $fileBytes,
                        'heartbeat_at' => now(),
                    ]);

                    if (ExportJob::query()->whereKey($this->exportJobId)->value('cancel_requested')) {
                        throw new ExportCancelledException;
                    }

                    $free = @disk_free_space($directory);

                    if ($free !== false && $free < $minimumFreeBytes) {
                        throw new DatabaseOperationException('The export was stopped because the disk is almost full (less than '.self::gigabytes($minimumFreeBytes).' free).');
                    }
                },
            );
        } catch (ExportCancelledException) {
            $this->finish(ExportStatus::Cancelled, null);

            return;
        } catch (DatabaseOperationException $exception) {
            $this->finish(ExportStatus::Failed, $exception->getMessage());

            return;
        }

        if (! $result->succeeded) {
            $this->finish(ExportStatus::Failed, $result->error, $result->bytesWritten, $result->fileBytes);

            return;
        }

        $this->finish(ExportStatus::Completed, null, $result->bytesWritten, $result->fileBytes);
    }

    /**
     * The worker crashed or timed out: say so, and remove the partial file.
     */
    public function failed(?Throwable $exception): void
    {
        $updated = ExportJob::query()->whereKey($this->exportJobId)
            ->whereIn('status', [ExportStatus::Queued, ExportStatus::Exporting])
            ->update([
                'status' => ExportStatus::Failed,
                'last_error' => 'The export stopped unexpectedly (the worker crashed or timed out).',
                'credentials' => null,
                'finished_at' => now(),
            ]);

        if ($updated > 0) {
            $export = ExportJob::query()->find($this->exportJobId);

            if ($export !== null) {
                File::delete($export->path());
            }

            $this->audit(ExportStatus::Failed, 'Worker crashed or timed out.');
        }
    }

    private function finish(ExportStatus $status, ?string $error, ?int $bytesWritten = null, ?int $fileBytes = null): void
    {
        $attributes = [
            'status' => $status,
            'last_error' => $error,
            'credentials' => null,
            'heartbeat_at' => now(),
            'finished_at' => now(),
        ];

        if ($bytesWritten !== null) {
            $attributes['bytes_written'] = $bytesWritten;
            $attributes['file_bytes'] = $fileBytes ?? 0;
        }

        if ($status === ExportStatus::Completed) {
            $attributes['expires_at'] = now()->addHours(config()->integer('dbnova.retention.export_file_hours'));
        }

        $updated = ExportJob::query()->whereKey($this->exportJobId)->where('status', ExportStatus::Exporting)->update($attributes);

        if ($updated === 0) {
            // Recovery or a crash handler already ended this export; its file must not outlive that.
            $export = ExportJob::query()->find($this->exportJobId);

            if ($export !== null && $export->status !== ExportStatus::Completed) {
                File::delete($export->path());
            }

            return;
        }

        $this->audit($status, $error);
    }

    /**
     * Exports copy data out of the server, so they are audited (names and sizes only).
     */
    private function audit(ExportStatus $status, ?string $error = null): void
    {
        $export = ExportJob::query()->with('user')->find($this->exportJobId);

        if ($export === null) {
            return;
        }

        AuditLog::query()->create([
            'user_id' => $export->user_id,
            'mysql_username' => $export->user?->username,
            'action' => 'export.'.$status->value,
            'database_name' => $export->database_name,
            'details' => [
                'tables' => $export->tables === null ? 'all' : count($export->tables),
                'format' => $export->format->value,
                'bytes' => $export->bytes_written,
            ],
            'succeeded' => $status === ExportStatus::Completed,
            'error' => $error === null ? null : mb_strimwidth($error, 0, 500, '…'),
        ]);
    }

    private static function gigabytes(int $bytes): string
    {
        return round($bytes / 1024 ** 3, 1).' GB';
    }
}

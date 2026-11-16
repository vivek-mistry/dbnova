<?php

namespace App\Jobs;

use App\Database\Contracts\DatabaseAdapterInterface;
use App\Database\DTOs\Export\CsvExportRequest;
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
 * Dumps a database to a file with mysqldump, or writes a table or a SQL
 * editor result as CSV. The payload is the export id
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

    /** Upper bound for the heartbeat lease, whatever the config says (4 h). */
    private const int MAX_QUERY_LEASE_SECONDS = 14400;

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

        $onProgress = function (int $bytesWritten, int $fileBytes, ?int $rowsWritten = null) use (&$lastWrite, $directory, $minimumFreeBytes): void {
            // A CSV export reports (0, 0, 0) only before its query and when the query returns:
            // always record those, so the heartbeat lease ends as soon as rows start arriving.
            $queryMilestone = $rowsWritten === 0 && $bytesWritten === 0;

            if (! $queryMilestone && microtime(true) - $lastWrite < self::PROGRESS_INTERVAL) {
                return;
            }

            $lastWrite = microtime(true);
            ExportJob::query()->whereKey($this->exportJobId)->update([
                'bytes_written' => $bytesWritten,
                'file_bytes' => $fileBytes,
                'rows_written' => $rowsWritten ?? 0,
                'heartbeat_at' => now(),
            ]);

            if (ExportJob::query()->whereKey($this->exportJobId)->value('cancel_requested')) {
                throw new ExportCancelledException;
            }

            $free = @disk_free_space($directory);

            if ($free !== false && $free < $minimumFreeBytes) {
                throw new DatabaseOperationException('The export was stopped because the disk is almost full (less than '.self::gigabytes($minimumFreeBytes).' free).');
            }
        };

        try {
            $result = $export->format->isCsv()
                ? $adapter->exporter()->exportCsv(
                    new CsvExportRequest(
                        $credentials,
                        $export->path(),
                        $export->format,
                        $export->database_name === '' ? null : $export->database_name,
                        $export->query === null ? ($export->tables[0] ?? null) : null,
                        $export->query,
                        (bool) ($export->options['spreadsheetSafe'] ?? false),
                    ),
                    $onProgress,
                    function (int $connectionId): void {
                        // MySQL may work for a long time before the first row arrives, and this
                        // process cannot report in meanwhile: take a heartbeat lease so recovery
                        // does not mistake the wait for a dead worker.
                        ExportJob::query()->whereKey($this->exportJobId)->update([
                            'mysql_connection_id' => $connectionId,
                            'heartbeat_at' => now()->addSeconds(min(config()->integer('dbnova.export_query_lease_seconds'), self::MAX_QUERY_LEASE_SECONDS)),
                        ]);
                    },
                )
                : $adapter->exporter()->export(
                    new ExportRequest(
                        $export->database_name,
                        $credentials,
                        $export->path(),
                        $export->format,
                        $export->tables ?? [],
                        in_array($export->options['content'] ?? null, ['structure', 'data'], true) ? $export->options['content'] : 'both',
                        (bool) ($export->options['includePrograms'] ?? true),
                    ),
                    $onProgress,
                );
        } catch (ExportCancelledException) {
            $this->finish(ExportStatus::Cancelled, null);

            return;
        } catch (DatabaseOperationException $exception) {
            // Cancel stops a running CSV query with KILL QUERY, which surfaces here as an error.
            $cancelled = (bool) ExportJob::query()->whereKey($this->exportJobId)->value('cancel_requested');
            $this->finish($cancelled ? ExportStatus::Cancelled : ExportStatus::Failed, $cancelled ? null : $exception->getMessage());

            return;
        }

        if (! $result->succeeded) {
            $this->finish(ExportStatus::Failed, $result->error, $result->bytesWritten, $result->fileBytes);

            return;
        }

        $this->finish(ExportStatus::Completed, null, $result->bytesWritten, $result->fileBytes, $result->rowsWritten);
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

    private function finish(ExportStatus $status, ?string $error, ?int $bytesWritten = null, ?int $fileBytes = null, ?int $rowsWritten = null): void
    {
        $attributes = [
            'status' => $status,
            'last_error' => $error,
            'credentials' => null,
            'mysql_connection_id' => null,
            'heartbeat_at' => now(),
            'finished_at' => now(),
        ];

        if ($rowsWritten !== null) {
            $attributes['rows_written'] = $rowsWritten;
        }

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
            'database_name' => $export->database_name === '' ? null : $export->database_name,
            'details' => [
                'source' => $export->source(),
                'tables' => $export->tables === null ? 'all' : count($export->tables),
                'format' => $export->format->value,
                'bytes' => $export->bytes_written,
                'rows' => $export->format->isCsv() ? $export->rows_written : null,
            ],
            'succeeded' => $status === ExportStatus::Completed,
            // MySQL errors quote the SQL near the problem; a query export's SQL stays out of the audit log.
            'error' => match (true) {
                $error === null => null,
                $export->source() === 'query' => 'The query export failed.',
                default => mb_strimwidth($error, 0, 500, '…'),
            },
        ]);
    }

    private static function gigabytes(int $bytes): string
    {
        return round($bytes / 1024 ** 3, 1).' GB';
    }
}

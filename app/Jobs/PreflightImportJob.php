<?php

namespace App\Jobs;

use App\Database\Contracts\DatabaseAdapterInterface;
use App\Database\DTOs\Import\PreflightReport;
use App\Database\Import\ImportCancelledException;
use App\Database\Import\SourceException;
use App\Database\Import\SourceStream;
use App\Enums\ImportStatus;
use App\Enums\UploadStatus;
use App\Models\ImportJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Scans an uploaded dump once, streaming, and records the preflight
 * report on the import: ready (accepted) or rejected. It reads only the
 * file, so it needs no MySQL credentials. The payload is the import id.
 */
class PreflightImportJob implements ShouldQueue
{
    use Queueable;

    /** Never retried: a second run would only repeat the same scan. */
    public int $tries = 1;

    /** Seconds; below the queue's retry_after (25 h) so the job is never run twice. */
    public int $timeout = 86400;

    public bool $failOnTimeout = true;

    /** Progress is written at most this often (seconds). */
    private const float PROGRESS_INTERVAL = 2.0;

    public function __construct(public string $importJobId)
    {
        $this->onQueue('imports');
    }

    public function handle(DatabaseAdapterInterface $adapter): void
    {
        $claimed = ImportJob::query()->whereKey($this->importJobId)->where('status', ImportStatus::Pending)
            ->update(['status' => ImportStatus::Preflight, 'heartbeat_at' => now(), 'bytes_scanned' => 0]);

        if ($claimed === 0) {
            return; // Cancelled before it started, or already handled.
        }

        $import = ImportJob::query()->with('upload')->findOrFail($this->importJobId);
        $upload = $import->upload;

        if ($upload === null || $upload->status !== UploadStatus::Uploaded) {
            $this->reject('The uploaded file is no longer available. Upload it again.');

            return;
        }

        try {
            $source = SourceStream::open($upload->path(), $upload->format);
        } catch (SourceException $exception) {
            $this->reject($exception->getMessage());

            return;
        }

        $import->update(['bytes_total' => $source->total()]);
        $lastWrite = 0.0; // The first chunk always records progress and checks for a cancel.

        try {
            $report = $adapter->importer()->preflight($source, function (int $position) use (&$lastWrite): void {
                if (microtime(true) - $lastWrite < self::PROGRESS_INTERVAL) {
                    return;
                }

                $lastWrite = microtime(true);
                ImportJob::query()->whereKey($this->importJobId)->update(['bytes_scanned' => $position, 'heartbeat_at' => now()]);

                if (ImportJob::query()->whereKey($this->importJobId)->value('cancel_requested')) {
                    throw new ImportCancelledException;
                }
            });
        } catch (ImportCancelledException) {
            $this->finish(ImportStatus::Cancelled, []);

            return;
        } finally {
            $source->close();
        }

        $this->finish($report->accepted() ? ImportStatus::Ready : ImportStatus::Rejected, [
            'preflight' => $report->toArray(),
            'bytes_scanned' => $source->total(),
        ]);
    }

    /**
     * The worker crashed or timed out: say so instead of leaving "preflight" forever.
     */
    public function failed(?Throwable $exception): void
    {
        ImportJob::query()->whereKey($this->importJobId)
            ->whereIn('status', [ImportStatus::Pending, ImportStatus::Preflight])
            ->update([
                'status' => ImportStatus::Failed,
                'last_error' => 'Checking the file stopped unexpectedly. Try the import again.',
                'finished_at' => now(),
            ]);
    }

    private function reject(string $problem): void
    {
        $this->finish(ImportStatus::Rejected, ['preflight' => PreflightReport::rejected($problem)->toArray()]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function finish(ImportStatus $status, array $attributes): void
    {
        ImportJob::query()->whereKey($this->importJobId)->where('status', ImportStatus::Preflight)->update([
            ...$attributes,
            'status' => $status,
            'heartbeat_at' => now(),
            'finished_at' => $status->isFinished() ? now() : null,
        ]);
    }
}

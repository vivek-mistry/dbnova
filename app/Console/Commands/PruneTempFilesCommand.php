<?php

namespace App\Console\Commands;

use App\Enums\ExportStatus;
use App\Enums\ImportStatus;
use App\Enums\UploadStatus;
use App\Models\ExportJob;
use App\Models\ImportJob;
use App\Models\Upload;
use App\Services\Import\UploadStore;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Deletes upload files that are no longer needed, following the retention
 * config, and credential files a crashed process may have left behind.
 */
#[Signature('dbnova:prune-temp')]
#[Description('Delete expired uploads and exports, and leftover temporary files')]
class PruneTempFilesCommand extends Command
{
    /** Option files hold credentials; one older than this was left behind by a crash. */
    private const int STALE_OPTION_FILE_SECONDS = 3600;

    /**
     * Execute the console command.
     */
    public function handle(UploadStore $store): int
    {
        $expired = 0;

        // Uploads that never finished.
        $incomplete = Upload::query()
            ->where('status', UploadStatus::Uploading)
            ->where('updated_at', '<', now()->subHours(config()->integer('dbnova.retention.incomplete_upload_hours')))
            ->get();

        // Finished uploads kept for a retry, unless an import is still using them.
        $finished = Upload::query()
            ->where('status', UploadStatus::Uploaded)
            ->where('updated_at', '<', now()->subHours(config()->integer('dbnova.retention.failed_import_hours')))
            ->whereDoesntHave('imports', fn ($query) => $query->whereIn('status', [
                ImportStatus::Pending, ImportStatus::Preflight, ImportStatus::Queued, ImportStatus::Importing,
            ]))
            ->get();

        foreach ($incomplete->concat($finished) as $upload) {
            $store->deleteFiles($upload);
            $upload->update(['status' => UploadStatus::Expired]);

            // A checked import that was never started can no longer run.
            ImportJob::query()->where('upload_id', $upload->id)->where('status', ImportStatus::Ready)
                ->update(['status' => ImportStatus::Cancelled, 'last_error' => 'The uploaded file expired before the import was started.', 'finished_at' => now()]);

            $expired++;
        }

        $removedOptionFiles = 0;

        foreach (File::glob(config()->string('dbnova.paths.tmp').'/*.cnf') as $optionFile) {
            if (is_file($optionFile) && filemtime($optionFile) < time() - self::STALE_OPTION_FILE_SECONDS) {
                File::delete($optionFile);
                $removedOptionFiles++;
            }
        }

        $expiredExports = 0;

        foreach (ExportJob::query()->where('status', ExportStatus::Completed)->where('expires_at', '<', now())->get() as $export) {
            File::delete($export->path());
            // The statement may contain literals (emails, tokens); it is not needed once the file is gone.
            $export->update(['status' => ExportStatus::Expired, 'query' => null]);
            $expiredExports++;
        }

        // Files of exports that did not finish are deleted by the job; this catches a crash in between.
        $removedExportFiles = 0;
        $keep = ExportJob::query()->whereIn('status', [ExportStatus::Queued, ExportStatus::Exporting, ExportStatus::Completed])->pluck('id')->flip();

        foreach (File::glob(config()->string('dbnova.paths.exports').'/*') as $file) {
            $id = explode('.', basename($file), 2)[0];

            if (is_file($file) && ! $keep->has($id) && filemtime($file) < time() - self::STALE_OPTION_FILE_SECONDS) {
                File::delete($file);
                $removedExportFiles++;
            }
        }

        $this->info("Expired {$expired} upload(s); removed {$removedOptionFiles} leftover option file(s).");
        $this->info("Expired {$expiredExports} export(s); removed {$removedExportFiles} leftover export file(s).");

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Enums\ImportStatus;
use App\Models\AuditLog;
use App\Models\ImportJob;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * A running import or file check writes a heartbeat every 2 seconds, even
 * while MySQL works on one long statement. When the heartbeat stops (the
 * worker was killed, the server restarted), the import is marked
 * interrupted. It is never resumed: what MySQL applied is unknown.
 */
#[Signature('dbnova:recover-jobs')]
#[Description('Mark imports whose worker stopped reporting as interrupted')]
class RecoverImportJobsCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $staleBefore = now()->subSeconds(config()->integer('dbnova.import_heartbeat_stale_seconds'));
        $recovered = 0;

        $stale = ImportJob::query()
            ->whereIn('status', [ImportStatus::Preflight, ImportStatus::Importing])
            ->where(fn ($query) => $query->whereNull('heartbeat_at')->orWhere('heartbeat_at', '<', $staleBefore))
            ->get();

        foreach ($stale as $import) {
            $importing = $import->status === ImportStatus::Importing;

            $updated = ImportJob::query()->whereKey($import->id)->where('status', $import->status)->update([
                'status' => $importing ? ImportStatus::Interrupted : ImportStatus::Failed,
                'last_error' => $importing
                    ? 'The import stopped reporting progress (the worker was stopped or the server restarted). Data may be partially imported.'
                    : 'Checking the file stopped (the worker was stopped or the server restarted). Nothing was sent to MySQL. Try again.',
                'credentials' => null,
                'finished_at' => now(),
            ]);

            if ($updated === 0) {
                continue;
            }

            $recovered++;

            if ($importing) {
                AuditLog::query()->create([
                    'user_id' => $import->user_id,
                    'mysql_username' => $import->user?->username,
                    'action' => 'import.interrupted',
                    'database_name' => $import->database_name,
                    'details' => ['bytesSent' => $import->bytes_streamed, 'statements' => $import->statements_seen],
                    'succeeded' => false,
                    'error' => 'Worker heartbeat stopped.',
                ]);
            }
        }

        // A queued import holds the user's password (encrypted) until a worker starts it;
        // if no worker does within a session lifetime, give up and wipe it.
        $abandoned = ImportJob::query()
            ->where('status', ImportStatus::Queued)
            ->where('updated_at', '<', now()->subMinutes(config()->integer('session.lifetime')))
            ->update([
                'status' => ImportStatus::Failed,
                'last_error' => 'No queue worker started the import in time, so the saved sign-in was discarded. Nothing was sent to MySQL. Start the import again once a worker is running.',
                'credentials' => null,
                'finished_at' => now(),
            ]);

        $this->info("Recovered {$recovered} stalled import(s); gave up on {$abandoned} import(s) no worker started.");

        return self::SUCCESS;
    }
}

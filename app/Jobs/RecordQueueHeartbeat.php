<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class RecordQueueHeartbeat implements ShouldQueue
{
    use Queueable;

    public const string CACHE_KEY = 'dbnova:queue-heartbeat';

    /**
     * Record that a queue worker is alive and processing jobs.
     */
    public function handle(): void
    {
        Cache::forever(self::CACHE_KEY, now()->getTimestamp());
    }
}

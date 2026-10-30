<?php

use App\Jobs\RecordQueueHeartbeat;
use Illuminate\Support\Facades\Cache;

test('it records the current time as the queue heartbeat', function () {
    $this->freezeTime();

    (new RecordQueueHeartbeat)->handle();

    expect(Cache::get(RecordQueueHeartbeat::CACHE_KEY))->toBe(now()->getTimestamp());
});

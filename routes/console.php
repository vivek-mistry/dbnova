<?php

use App\Jobs\RecordQueueHeartbeat;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new RecordQueueHeartbeat)->everyMinute();
Schedule::command('dbnova:recover-jobs')->everyMinute()->withoutOverlapping();
Schedule::command('dbnova:prune-temp')->hourly()->withoutOverlapping();

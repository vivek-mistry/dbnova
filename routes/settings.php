<?php

use App\Http\Middleware\EnsureMySQLCredentials;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', EnsureMySQLCredentials::class])->group(function () {
    Route::redirect('settings', '/settings/appearance');

    Route::inertia('settings/appearance', 'settings/Appearance')->name('appearance.edit');
});

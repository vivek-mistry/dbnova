<?php

use App\Http\Controllers\Settings\PreferencesController;
use App\Http\Middleware\EnsureMySQLCredentials;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', EnsureMySQLCredentials::class])->group(function () {
    Route::redirect('settings', '/settings/preferences');

    Route::inertia('settings/appearance', 'settings/Appearance')->name('appearance.edit');
    Route::get('settings/preferences', [PreferencesController::class, 'edit'])->name('preferences.edit');
    Route::patch('settings/preferences', [PreferencesController::class, 'update'])->name('preferences.update');
});

<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\SystemController;
use App\Http\Middleware\EnsureMySQLCredentials;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth', EnsureMySQLCredentials::class])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::inertia('databases', 'ComingSoon', ['title' => 'Databases', 'phase' => 2])->name('databases.index');
    Route::inertia('sql', 'ComingSoon', ['title' => 'SQL Editor', 'phase' => 5])->name('sql.index');
    Route::inertia('import-export', 'ComingSoon', ['title' => 'Import / Export', 'phase' => 6])->name('import-export.index');
    Route::inertia('processes', 'ComingSoon', ['title' => 'Processes', 'phase' => 8])->name('processes.index');

    Route::get('system', [SystemController::class, 'index'])->name('system.index');
});

require __DIR__.'/settings.php';

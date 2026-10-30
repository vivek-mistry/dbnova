<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DatabaseController;
use App\Http\Controllers\DatabaseTreeController;
use App\Http\Controllers\SystemController;
use App\Http\Controllers\TableController;
use App\Http\Controllers\TableRowsController;
use App\Http\Middleware\EnsureMySQLCredentials;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth', EnsureMySQLCredentials::class])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::resource('databases', DatabaseController::class)
        ->only(['index', 'store', 'show', 'update', 'destroy'])
        ->parameters(['databases' => 'database']);

    Route::prefix('databases/{database}/tables/{table}')->name('tables.')->group(function () {
        Route::get('/', [TableController::class, 'show'])->name('show');
        Route::get('structure', [TableController::class, 'structure'])->name('structure');
        Route::get('rows', [TableRowsController::class, 'index'])->name('rows');
        Route::get('count', [TableRowsController::class, 'count'])->name('count');
        Route::get('value', [TableRowsController::class, 'value'])->name('value');
    });

    Route::get('database-tree', [DatabaseTreeController::class, 'index'])->name('database-tree.index');
    Route::get('database-tree/{database}', [DatabaseTreeController::class, 'tables'])->name('database-tree.tables');

    Route::inertia('sql', 'ComingSoon', ['title' => 'SQL Editor', 'phase' => 5])->name('sql.index');
    Route::inertia('import-export', 'ComingSoon', ['title' => 'Import / Export', 'phase' => 6])->name('import-export.index');
    Route::inertia('processes', 'ComingSoon', ['title' => 'Processes', 'phase' => 8])->name('processes.index');

    Route::get('system', [SystemController::class, 'index'])->name('system.index');
});

require __DIR__.'/settings.php';

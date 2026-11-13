<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DatabaseController;
use App\Http\Controllers\DatabaseTreeController;
use App\Http\Controllers\QueryController;
use App\Http\Controllers\RecordController;
use App\Http\Controllers\SchemaChangeController;
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

    Route::post('databases/{database}/schema/preview', [SchemaChangeController::class, 'preview'])->name('schema.preview');
    Route::post('databases/{database}/schema/apply', [SchemaChangeController::class, 'apply'])->name('schema.apply');

    Route::prefix('databases/{database}/tables/{table}')->name('tables.')->group(function () {
        Route::get('/', [TableController::class, 'show'])->name('show');
        Route::get('structure', [TableController::class, 'structure'])->name('structure');
        Route::get('rows', [TableRowsController::class, 'index'])->name('rows');
        Route::get('count', [TableRowsController::class, 'count'])->name('count');
        Route::get('value', [TableRowsController::class, 'value'])->name('value');
        Route::post('records', [RecordController::class, 'store'])->name('records.store');
        Route::patch('records', [RecordController::class, 'update'])->name('records.update');
        Route::delete('records', [RecordController::class, 'destroy'])->name('records.destroy');
    });

    Route::get('database-tree', [DatabaseTreeController::class, 'index'])->name('database-tree.index');
    Route::get('database-tree/{database}', [DatabaseTreeController::class, 'tables'])->name('database-tree.tables');

    Route::get('sql', [QueryController::class, 'index'])->name('sql.index');
    Route::post('sql/run', [QueryController::class, 'run'])->name('sql.run');
    Route::post('sql/cancel', [QueryController::class, 'cancel'])->name('sql.cancel');
    Route::get('sql/schema', [QueryController::class, 'schema'])->name('sql.schema');
    Route::patch('sql/history/{history}', [QueryController::class, 'save'])->name('sql.history.save');
    Route::inertia('import-export', 'ComingSoon', ['title' => 'Import / Export', 'phase' => 6])->name('import-export.index');
    Route::inertia('processes', 'ComingSoon', ['title' => 'Processes', 'phase' => 8])->name('processes.index');

    Route::get('system', [SystemController::class, 'index'])->name('system.index');
});

require __DIR__.'/settings.php';

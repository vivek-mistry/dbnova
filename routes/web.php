<?php

use App\Http\Controllers\AuditController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DatabaseController;
use App\Http\Controllers\DatabaseTreeController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\MaintenanceController;
use App\Http\Controllers\ProcessController;
use App\Http\Controllers\QueryController;
use App\Http\Controllers\RecordController;
use App\Http\Controllers\SchemaChangeController;
use App\Http\Controllers\ServerController;
use App\Http\Controllers\SystemController;
use App\Http\Controllers\TableController;
use App\Http\Controllers\TableRowsController;
use App\Http\Controllers\UploadController;
use App\Http\Middleware\EnsureMySQLCredentials;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth', EnsureMySQLCredentials::class])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::resource('databases', DatabaseController::class)
        ->only(['index', 'store', 'show', 'update', 'destroy'])
        ->parameters(['databases' => 'database'])
        // Dropping re-checks the MySQL password: limit guessing.
        ->middlewareFor('destroy', 'throttle:5,1');

    Route::post('databases/{database}/schema/preview', [SchemaChangeController::class, 'preview'])->name('schema.preview');
    Route::post('databases/{database}/maintenance', MaintenanceController::class)->middleware('throttle:30,1')->name('maintenance.run');
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
    Route::get('import-export', [ImportController::class, 'index'])->name('import-export.index');
    Route::post('imports', [ImportController::class, 'store'])->name('imports.store');
    Route::get('imports/{import}', [ImportController::class, 'show'])->name('imports.show');
    Route::post('imports/{import}/cancel', [ImportController::class, 'cancel'])->name('imports.cancel');
    Route::post('imports/{import}/start', [ImportController::class, 'start'])->name('imports.start');
    Route::post('imports/{import}/reset-target', [ImportController::class, 'resetTarget'])->middleware('throttle:5,1')->name('imports.reset-target');

    Route::get('exports', [ExportController::class, 'index'])->name('exports.index');
    Route::get('exports/databases/{database}/tables', [ExportController::class, 'tables'])->name('exports.tables');
    Route::post('exports', [ExportController::class, 'store'])->middleware('throttle:30,1')->name('exports.store');
    Route::post('exports/query', [ExportController::class, 'storeQuery'])->middleware('throttle:30,1')->name('exports.query');
    Route::get('exports/{export}', [ExportController::class, 'show'])->whereUuid('export')->name('exports.show');
    Route::post('exports/{export}/cancel', [ExportController::class, 'cancel'])->whereUuid('export')->name('exports.cancel');
    Route::delete('exports/{export}', [ExportController::class, 'destroy'])->whereUuid('export')->name('exports.destroy');
    Route::get('exports/{export}/download', [ExportController::class, 'download'])
        ->whereUuid('export')->middleware('signed')->name('exports.download');

    Route::post('uploads', [UploadController::class, 'store'])->middleware('throttle:30,1')->name('uploads.store');
    Route::get('uploads/{upload}', [UploadController::class, 'show'])->name('uploads.show');
    Route::put('uploads/{upload}/chunks/{index}', [UploadController::class, 'chunk'])
        ->whereNumber('index')->middleware('throttle:uploads')->name('uploads.chunk');
    Route::delete('uploads/{upload}', [UploadController::class, 'destroy'])->name('uploads.destroy');
    Route::get('processes', [ProcessController::class, 'index'])->name('processes.index');
    Route::get('processes/list', [ProcessController::class, 'list'])->name('processes.list');
    Route::post('processes/{process}/kill', [ProcessController::class, 'kill'])
        ->whereNumber('process')->middleware('throttle:30,1')->name('processes.kill');
    Route::get('server/variables', [ServerController::class, 'variables'])->name('server.variables');
    Route::get('server/status', [ServerController::class, 'status'])->name('server.status');
    Route::get('server/metrics', [ServerController::class, 'metrics'])->name('server.metrics');

    Route::get('system', [SystemController::class, 'index'])->name('system.index');
    Route::get('system/audit', [AuditController::class, 'index'])->name('audit.index');
});

require __DIR__.'/settings.php';

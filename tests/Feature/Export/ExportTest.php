<?php

use App\Database\Contracts\DatabaseAdapterInterface;
use App\Database\Contracts\ExporterInterface;
use App\Database\DTOs\DatabaseInfo;
use App\Database\DTOs\Export\ExportRequest;
use App\Database\DTOs\Export\ExportResult;
use App\Database\DTOs\TableInfo;
use App\Database\Exceptions\DatabaseOperationException;
use App\Enums\ExportFormat;
use App\Enums\ExportStatus;
use App\Jobs\ExportDatabaseJob;
use App\Models\AuditLog;
use App\Models\ExportJob;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    $this->user = actingAsMySqlUser(User::factory()->create(['username' => 'root']), 'Secret@123');
    $this->directory = sys_get_temp_dir().'/dbnova-exports-'.bin2hex(random_bytes(4));
    config(['dbnova.paths.exports' => $this->directory, 'dbnova.paths.tmp' => $this->directory.'/tmp', 'dbnova.limits.min_free_disk_bytes' => 0]);
    File::ensureDirectoryExists($this->directory);

    /** A finished export whose file exists. */
    $this->completedExport = function (array $attributes = []): ExportJob {
        $export = ExportJob::factory()->for($this->user)->create([
            'status' => ExportStatus::Completed,
            'file_name' => 'shop-20261113-120000.sql',
            'file_bytes' => 9,
            'expires_at' => now()->addDay(),
            ...$attributes,
        ]);
        file_put_contents($export->path(), 'SELECT 1;');

        return $export;
    };
});

afterEach(function () {
    File::deleteDirectory($this->directory);
});

describe('starting', function () {
    beforeEach(function () {
        $this->fakes = fakeDatabaseAdapter();
        $this->fakes['schema']->shouldReceive('database')->andReturnUsing(
            fn (string $name) => $name === 'shop' ? new DatabaseInfo('shop', 'utf8mb4', 'utf8mb4_bin', 2, 100, false) : null,
        );
        $this->fakes['schema']->shouldReceive('tables')->with('shop')->andReturn([
            new TableInfo('orders', 'BASE TABLE', 'InnoDB', 3, 16384, 'utf8mb4_bin'),
            new TableInfo('customers', 'BASE TABLE', 'InnoDB', 3, 16384, 'utf8mb4_bin'),
        ]);
    });

    test('an export is queued with the signed-in credentials stored encrypted', function () {
        Queue::fake();

        $response = $this->postJson(route('exports.store'), [
            'database' => 'shop', 'tables' => ['orders'], 'format' => 'sql.gz', 'content' => 'both', 'includePrograms' => false,
        ])->assertCreated()->assertJson(['status' => 'queued', 'estimatedBytes' => 1024, 'downloadUrl' => null])->assertJsonMissingPath('credentials');

        $export = ExportJob::query()->sole();
        expect($export->credentials)->toBe(['username' => 'root', 'password' => 'Secret@123'])
            ->and($export->getRawOriginal('credentials'))->not->toContain('Secret@123')
            ->and($export->tables)->toBe(['orders'])
            ->and($export->format)->toBe(ExportFormat::SqlGz)
            ->and($export->options)->toBe(['content' => 'both', 'includePrograms' => false])
            ->and($export->file_name)->toMatch('/^shop-orders-\d{8}-\d{6}\.sql\.gz$/')
            ->and($response->json('id'))->toBe($export->id);
        Queue::assertPushedOn('exports', ExportDatabaseJob::class, fn (ExportDatabaseJob $job) => $job->exportJobId === $export->id);
        expect(AuditLog::query()->sole())->action->toBe('export.start')->database_name->toBe('shop');
    });

    test('unknown databases, virtual schemas and unknown tables are refused', function (array $payload, string $field) {
        Queue::fake();

        $this->postJson(route('exports.store'), ['format' => 'sql', 'content' => 'both', ...$payload])
            ->assertUnprocessable()->assertJsonValidationErrors($field);

        Queue::assertNothingPushed();
    })->with([
        'missing database' => [['database' => 'nope'], 'database'],
        'information_schema' => [['database' => 'information_schema'], 'database'],
        'missing table' => [['database' => 'shop', 'tables' => ['orders', 'nope']], 'tables'],
        'bad format' => [['database' => 'shop', 'format' => 'zip'], 'format'],
    ]);

    test('the export is refused when the disk could not hold it', function () {
        Queue::fake();
        $this->fakes['exporter']->shouldReceive('estimateBytes')->andReturn(PHP_INT_MAX >> 1);

        $this->postJson(route('exports.store'), ['database' => 'shop', 'format' => 'sql', 'content' => 'both'])
            ->assertUnprocessable()->assertJsonPath('message', fn (string $message) => str_contains($message, 'Not enough free disk space'));

        Queue::assertNothingPushed();
    });

    test('a user runs at most three exports at a time', function () {
        Queue::fake();
        ExportJob::factory()->for($this->user)->count(3)->create(['status' => ExportStatus::Exporting]);

        $this->postJson(route('exports.store'), ['database' => 'shop', 'format' => 'sql', 'content' => 'both'])->assertTooManyRequests();
    });

    test('read-only mode still allows exports', function () {
        Queue::fake();
        config(['dbnova.read_only' => true]);

        $this->postJson(route('exports.store'), ['database' => 'shop', 'format' => 'sql', 'content' => 'structure'])->assertCreated();
    });
});

describe('managing', function () {
    test('another user\'s export is not found', function () {
        $export = ExportJob::factory()->create();

        $this->getJson(route('exports.show', $export))->assertNotFound();
        $this->postJson(route('exports.cancel', $export))->assertNotFound();
        $this->deleteJson(route('exports.destroy', $export))->assertNotFound();
        $this->get(URL::temporarySignedRoute('exports.download', now()->addMinute(), ['export' => $export->id]))->assertNotFound();
    });

    test('cancelling a queued export ends it and wipes the saved sign-in; a running one is asked to stop', function () {
        $queued = ExportJob::factory()->for($this->user)->create(['credentials' => ['username' => 'root', 'password' => 'x']]);
        $running = ExportJob::factory()->for($this->user)->create(['status' => ExportStatus::Exporting]);

        $this->postJson(route('exports.cancel', $queued))->assertOk()->assertJson(['status' => 'cancelled']);
        $this->postJson(route('exports.cancel', $running))->assertOk()->assertJson(['status' => 'exporting', 'cancelRequested' => true]);

        expect($queued->refresh()->credentials)->toBeNull();
    });

    test('deleting a finished export removes its file', function () {
        $export = ($this->completedExport)();

        $this->deleteJson(route('exports.destroy', $export))->assertOk()->assertJson(['status' => 'expired', 'downloadUrl' => null]);

        expect(file_exists($export->path()))->toBeFalse();
    });

    test('the export page lists the user\'s exports', function () {
        fakeDatabaseAdapter();
        ($this->completedExport)();
        ExportJob::factory()->create();

        $this->get(route('exports.index'))->assertOk()
            ->assertInertia(fn ($page) => $page->component('import-export/Export')->has('exports', 1)->where('exports.0.status', 'completed'));
    });
});

describe('downloading', function () {
    test('a completed export streams through its signed link', function () {
        $export = ($this->completedExport)();
        $url = $this->getJson(route('exports.show', $export))->json('downloadUrl');

        $response = $this->get($url)->assertOk()->assertDownload('shop-20261113-120000.sql');

        expect($response->baseResponse->getFile()->getPathname())->toBe($export->path());
        expect(AuditLog::query()->sole()->action)->toBe('export.download');
    });

    test('an unsigned, tampered or expired link is refused', function () {
        $export = ($this->completedExport)();

        $this->get(route('exports.download', $export))->assertForbidden();
        $this->get(URL::temporarySignedRoute('exports.download', now()->addMinute(), ['export' => $export->id]).'x')->assertForbidden();

        $url = URL::temporarySignedRoute('exports.download', now()->addMinute(), ['export' => $export->id]);
        $this->travel(2)->minutes();
        $this->get($url)->assertForbidden();
    });

    test('an expired file is gone even with a valid link', function () {
        $export = ($this->completedExport)(['expires_at' => now()->subMinute()]);

        $this->get(URL::temporarySignedRoute('exports.download', now()->addMinute(), ['export' => $export->id]))->assertGone();
        expect($export->toStatus()['downloadUrl'])->toBeNull();
    });

    test('the signed link never outlives the file', function () {
        config(['dbnova.retention.export_link_minutes' => 60]);
        $export = ($this->completedExport)(['expires_at' => now()->addMinutes(5)]);

        parse_str((string) parse_url((string) $export->toStatus()['downloadUrl'], PHP_URL_QUERY), $query);

        expect((int) $query['expires'])->toBeLessThanOrEqual(now()->addMinutes(5)->getTimestamp());
    });
});

describe('the job', function () {
    beforeEach(function () {
        $this->exporter = Mockery::mock(ExporterInterface::class);
        $this->mock(DatabaseAdapterInterface::class)->shouldReceive('exporter')->andReturn($this->exporter);
        $this->export = ExportJob::factory()->for($this->user)->create([
            'tables' => ['orders'],
            'format' => ExportFormat::SqlGz,
            'options' => ['content' => 'data', 'includePrograms' => false],
            'credentials' => ['username' => 'root', 'password' => 'Secret@123'],
        ]);
        $this->runJob = fn () => (new ExportDatabaseJob($this->export->id))->handle(app(DatabaseAdapterInterface::class));
    });

    test('a successful export completes with an expiry, and the sign-in is wiped before mysqldump runs', function () {
        config(['dbnova.retention.export_file_hours' => 6]);

        $this->exporter->shouldReceive('export')->once()->withArgs(function (ExportRequest $request, callable $onProgress) {
            expect(ExportJob::query()->find($this->export->id)->credentials)->toBeNull()
                ->and($request->credentials)->toBe(['username' => 'root', 'password' => 'Secret@123'])
                ->and($request->tables)->toBe(['orders'])
                ->and($request->format)->toBe(ExportFormat::SqlGz)
                ->and($request->content)->toBe('data')
                ->and($request->includePrograms)->toBeFalse()
                ->and($request->path)->toBe($this->export->path());

            return true;
        })->andReturn(new ExportResult(true, 5000, 900));

        ($this->runJob)();

        $export = $this->export->refresh();
        expect($export->status)->toBe(ExportStatus::Completed)
            ->and($export->bytes_written)->toBe(5000)
            ->and($export->file_bytes)->toBe(900)
            ->and($export->expires_at->diffInHours(now()->addHours(6)))->toBeLessThan(1);
        expect(AuditLog::query()->sole())->action->toBe('export.completed')->succeeded->toBeTrue();
    });

    test('a mysqldump error fails the export with its message', function () {
        $this->exporter->shouldReceive('export')->andReturn(new ExportResult(false, 10, 5, 'mysqldump: Got error: 1044: Access denied'));

        ($this->runJob)();

        expect($this->export->refresh())->status->toBe(ExportStatus::Failed)->last_error->toContain('Access denied');
    });

    test('a database error before the dump fails the export', function () {
        $this->exporter->shouldReceive('export')->andThrow(new DatabaseOperationException('These tables no longer exist: orders.'));

        ($this->runJob)();

        expect($this->export->refresh())->status->toBe(ExportStatus::Failed)->last_error->toContain('no longer exist');
    });

    test('the cancel flag stops the dump from the progress callback', function () {
        $this->exporter->shouldReceive('export')->andReturnUsing(function (ExportRequest $request, callable $onProgress) {
            ExportJob::query()->whereKey($this->export->id)->update(['cancel_requested' => true]);
            $onProgress(100, 50);

            return new ExportResult(true, 100, 50);
        });

        ($this->runJob)();

        expect($this->export->refresh()->status)->toBe(ExportStatus::Cancelled);
    });

    test('progress stops the export when the disk is almost full', function () {
        config(['dbnova.limits.min_free_disk_bytes' => PHP_INT_MAX]);
        $this->exporter->shouldReceive('export')->andReturnUsing(function (ExportRequest $request, callable $onProgress) {
            $onProgress(100, 50);

            return new ExportResult(true, 100, 50);
        });

        ($this->runJob)();

        expect($this->export->refresh())->status->toBe(ExportStatus::Failed)->last_error->toContain('disk is almost full');
    });

    test('an export cancelled before a worker picked it up never runs', function () {
        $this->export->update(['status' => ExportStatus::Cancelled]);
        $this->exporter->shouldNotReceive('export');

        ($this->runJob)();

        expect($this->export->refresh()->status)->toBe(ExportStatus::Cancelled);
    });

    test('a worker that finishes after recovery ended the export leaves no file and no contradicting audit entry', function () {
        $this->exporter->shouldReceive('export')->andReturnUsing(function (ExportRequest $request) {
            ExportJob::query()->whereKey($this->export->id)->update(['status' => ExportStatus::Interrupted]);
            file_put_contents($request->path, 'complete dump');

            return new ExportResult(true, 13, 13);
        });

        ($this->runJob)();

        expect($this->export->refresh()->status)->toBe(ExportStatus::Interrupted)
            ->and(file_exists($this->export->path()))->toBeFalse()
            ->and(AuditLog::query()->where('action', 'export.completed')->exists())->toBeFalse();
    });

    test('a crashed worker marks the export failed and removes the partial file', function () {
        $this->export->update(['status' => ExportStatus::Exporting]);
        file_put_contents($this->export->path(), 'partial');

        (new ExportDatabaseJob($this->export->id))->failed(new RuntimeException('boom'));

        expect($this->export->refresh())->status->toBe(ExportStatus::Failed)->credentials->toBeNull();
        expect(file_exists($this->export->path()))->toBeFalse();
    });
});

describe('recovery and cleanup', function () {
    test('a stalled export is interrupted and its partial file deleted; an unstarted one loses its sign-in', function () {
        $stalled = ExportJob::factory()->for($this->user)->create(['status' => ExportStatus::Exporting, 'heartbeat_at' => now()->subMinutes(5)]);
        $alive = ExportJob::factory()->for($this->user)->create(['status' => ExportStatus::Exporting, 'heartbeat_at' => now()]);
        $abandoned = ExportJob::factory()->for($this->user)->create(['credentials' => ['username' => 'root', 'password' => 'x']]);
        ExportJob::query()->whereKey($abandoned->id)->update(['updated_at' => now()->subDay()]);
        file_put_contents($stalled->path(), 'partial');

        $this->artisan('dbnova:recover-jobs')->assertSuccessful();

        expect($stalled->refresh()->status)->toBe(ExportStatus::Interrupted)
            ->and($alive->refresh()->status)->toBe(ExportStatus::Exporting)
            ->and($abandoned->refresh()->status)->toBe(ExportStatus::Failed)
            ->and($abandoned->credentials)->toBeNull()
            ->and(file_exists($stalled->path()))->toBeFalse();
    });

    test('expired export files and leftovers are pruned', function () {
        $expired = ($this->completedExport)(['expires_at' => now()->subMinute()]);
        $current = ($this->completedExport)();
        file_put_contents($orphan = $this->directory.'/'.fake()->uuid().'.sql', 'left behind');
        touch($orphan, time() - 7200);

        $this->artisan('dbnova:prune-temp')->assertSuccessful();

        expect($expired->refresh()->status)->toBe(ExportStatus::Expired)
            ->and(file_exists($expired->path()))->toBeFalse()
            ->and(file_exists($current->path()))->toBeTrue()
            ->and(file_exists($orphan))->toBeFalse();
    });
});

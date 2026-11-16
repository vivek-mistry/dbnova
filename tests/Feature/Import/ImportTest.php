<?php

use App\Database\Contracts\DatabaseAdapterInterface;
use App\Database\DTOs\DatabaseInfo;
use App\Database\DTOs\TableInfo;
use App\Enums\ImportStatus;
use App\Enums\UploadStatus;
use App\Jobs\PreflightImportJob;
use App\Models\ImportJob;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    ['schema' => $this->schema] = fakeDatabaseAdapter();
    $this->user = actingAsMySqlUser();
    $this->directory = sys_get_temp_dir().'/dbnova-imports-'.bin2hex(random_bytes(4));
    config(['dbnova.paths.uploads' => $this->directory]);
    File::ensureDirectoryExists($this->directory);

    /** An uploaded file with the given contents, owned by the signed-in user. */
    $this->uploaded = function (string $contents, string $format = 'sql'): Upload {
        $upload = Upload::factory()->for($this->user)->uploaded()->create(['format' => $format, 'size' => strlen($contents)]);
        file_put_contents($upload->path(), $contents);

        return $upload;
    };

    $this->runPreflight = fn (ImportJob $import) => (new PreflightImportJob($import->id))->handle(app(DatabaseAdapterInterface::class));
});

afterEach(function () {
    File::deleteDirectory($this->directory);
});

function importTargetDatabase(): DatabaseInfo
{
    return new DatabaseInfo('target', 'utf8mb4', 'utf8mb4_0900_ai_ci', 0, 0, false);
}

describe('page', function () {
    test('lists the user\'s recent imports and loads databases later', function () {
        ImportJob::factory()->for($this->user)->create(['database_name' => 'mine']);
        ImportJob::factory()->for(User::factory())->create(['database_name' => 'theirs']);
        $this->schema->shouldReceive('databases')->andReturn([importTargetDatabase(), new DatabaseInfo('mysql', 'utf8mb4', 'x', 1, 1, true)]);

        $this->get(route('import-export.index', ['database' => 'target']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('import-export/Import')
                ->where('database', 'target')
                ->has('imports', 1)
                ->where('imports.0.database', 'mine')
                ->missing('databases')
                ->loadDeferredProps(fn (Assert $reload) => $reload->where('databases', ['target'])));
    });
});

describe('creating an import', function () {
    test('queues the file check for a finished upload and an existing database', function () {
        Queue::fake();
        $upload = ($this->uploaded)('SELECT 1;');
        $this->schema->shouldReceive('database')->with('target')->andReturn(importTargetDatabase());

        $response = $this->postJson(route('imports.store'), ['uploadId' => $upload->id, 'database' => 'target']);

        $response->assertCreated()->assertJson(['status' => 'pending', 'database' => 'target', 'bytesTotal' => 9]);
        Queue::assertPushedOn('imports', PreflightImportJob::class, fn (PreflightImportJob $job) => $job->importJobId === $response->json('id'));
    });

    test('needs a finished upload of this user', function (Closure $makeUpload) {
        Queue::fake();
        $upload = $makeUpload($this->user);

        $this->postJson(route('imports.store'), ['uploadId' => $upload->id, 'database' => 'target'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['uploadId' => 'The file has not finished uploading.']);

        Queue::assertNothingPushed();
    })->with([
        'still uploading' => fn (User $user) => Upload::factory()->for($user)->create(),
        'another user\'s upload' => fn () => Upload::factory()->uploaded()->create(),
    ]);

    test('needs an existing database', function () {
        Queue::fake();
        $upload = ($this->uploaded)('SELECT 1;');
        $this->schema->shouldReceive('database')->with('missing')->andReturnNull();

        $this->postJson(route('imports.store'), ['uploadId' => $upload->id, 'database' => 'missing'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['database' => 'That database does not exist.']);

        Queue::assertNothingPushed();
    });

    test('refuses MySQL system databases', function () {
        Queue::fake();
        $upload = ($this->uploaded)('SELECT 1;');
        $this->schema->shouldReceive('database')->with('mysql')->andReturn(new DatabaseInfo('mysql', 'utf8mb4', 'x', 40, 1, true));

        $this->postJson(route('imports.store'), ['uploadId' => $upload->id, 'database' => 'mysql'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['database' => 'Imports into MySQL system databases are not allowed.']);

        Queue::assertNothingPushed();
    });
});

describe('the file check', function () {
    test('an accepted dump becomes ready and reports tables that already exist in the target', function () {
        $import = ImportJob::factory()->for($this->user)->create([
            'upload_id' => ($this->uploaded)((string) file_get_contents(base_path('tests/Fixtures/dumps/single-db.sql')))->id,
            'database_name' => 'target',
        ]);

        ($this->runPreflight)($import);

        $import->refresh();
        expect($import->status)->toBe(ImportStatus::Ready);
        expect($import->preflight['sourceDatabase'])->toBe('shop');
        expect($import->bytes_scanned)->toBe($import->bytes_total);
        expect($import->finished_at)->toBeNull();

        $this->schema->shouldReceive('tables')->with('target')->andReturn([
            new TableInfo('orders', 'BASE TABLE', 'InnoDB', 0, 0, null),
            new TableInfo('invoices', 'BASE TABLE', 'InnoDB', 0, 0, null),
        ]);

        $this->getJson(route('imports.show', $import))
            ->assertOk()
            ->assertJson(['status' => 'ready', 'conflicts' => ['orders'], 'preflight' => ['accepted' => true, 'tables' => ['customers', 'orders']]])
            ->assertJsonMissingPath('credentials');
    });

    test('a dump of several databases is rejected and finished', function () {
        $import = ImportJob::factory()->for($this->user)->create([
            'upload_id' => ($this->uploaded)((string) file_get_contents(base_path('tests/Fixtures/dumps/multi-db.sql')))->id,
        ]);

        ($this->runPreflight)($import);

        $import->refresh();
        expect($import->status)->toBe(ImportStatus::Rejected);
        expect($import->preflight['problems'][0])->toContain('2 databases');
        expect($import->finished_at)->not->toBeNull();
    });

    test('a compressed dump is checked through its archive', function () {
        $sql = (string) file_get_contents(base_path('tests/Fixtures/dumps/plain.sql'));
        $import = ImportJob::factory()->for($this->user)->create(['upload_id' => ($this->uploaded)(gzencode($sql), 'gz')->id]);

        ($this->runPreflight)($import);

        expect($import->refresh()->status)->toBe(ImportStatus::Ready);
        expect($import->preflight['sqlBytes'])->toBe(strlen($sql));
    });

    test('an unreadable or missing file is rejected with the reason', function (Closure $breakUpload, string $problem) {
        $upload = ($this->uploaded)('SELECT 1;', 'gz');
        $breakUpload($upload);
        $import = ImportJob::factory()->for($this->user)->create(['upload_id' => $upload->id]);

        ($this->runPreflight)($import);

        expect($import->refresh()->status)->toBe(ImportStatus::Rejected);
        expect($import->preflight['problems'])->toBe([$problem]);
    })->with([
        'not really gzip' => [fn () => null, 'The file is named .gz but is not gzip-compressed.'],
        'file deleted' => [fn (Upload $upload) => unlink($upload->path()), 'The uploaded file is no longer on disk.'],
        'upload cancelled' => [fn (Upload $upload) => $upload->update(['status' => UploadStatus::Cancelled]), 'The uploaded file is no longer available. Upload it again.'],
    ]);

    test('a cancel requested while checking stops the scan', function () {
        $import = ImportJob::factory()->for($this->user)->create([
            'upload_id' => ($this->uploaded)(str_repeat("INSERT INTO t VALUES (1);\n", 100))->id,
            'cancel_requested' => true,
        ]);

        ($this->runPreflight)($import);

        expect($import->refresh()->status)->toBe(ImportStatus::Cancelled);
        expect($import->finished_at)->not->toBeNull();
    });

    test('an import that is no longer pending is left alone', function () {
        $import = ImportJob::factory()->for($this->user)->create(['status' => ImportStatus::Cancelled]);

        ($this->runPreflight)($import);

        expect($import->refresh()->status)->toBe(ImportStatus::Cancelled);
        expect($import->preflight)->toBeNull();
    });

    test('a crashed check is marked failed instead of hanging', function () {
        $import = ImportJob::factory()->for($this->user)->create(['status' => ImportStatus::Preflight]);

        (new PreflightImportJob($import->id))->failed(new RuntimeException('worker died'));

        expect($import->refresh()->status)->toBe(ImportStatus::Failed);
        expect($import->last_error)->toBe('Checking the file stopped unexpectedly. Try the import again.');
    });
});

describe('cancelling', function () {
    test('a waiting or checked import is cancelled at once', function (ImportStatus $status) {
        $import = ImportJob::factory()->for($this->user)->create(['status' => $status]);

        $this->postJson(route('imports.cancel', $import))->assertOk()->assertJson(['status' => 'cancelled']);
    })->with([ImportStatus::Pending, ImportStatus::Ready]);

    test('a running check is asked to stop', function () {
        $import = ImportJob::factory()->for($this->user)->create(['status' => ImportStatus::Preflight]);

        $this->postJson(route('imports.cancel', $import))->assertOk()->assertJson(['status' => 'preflight', 'cancelRequested' => true]);
    });

    test('a finished import stays as it is', function () {
        $import = ImportJob::factory()->for($this->user)->create(['status' => ImportStatus::Rejected]);

        $this->postJson(route('imports.cancel', $import))->assertOk()->assertJson(['status' => 'rejected', 'cancelRequested' => false]);
    });
});

test('another user\'s import cannot be seen or cancelled', function () {
    $import = ImportJob::factory()->for(User::factory())->create();

    $this->getJson(route('imports.show', $import))->assertNotFound();
    $this->postJson(route('imports.cancel', $import))->assertNotFound();
});

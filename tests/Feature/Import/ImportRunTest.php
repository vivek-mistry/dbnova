<?php

use App\Database\Adapters\MySQL\MySQLConnector;
use App\Database\Contracts\DatabaseAdapterInterface;
use App\Database\Contracts\ImporterInterface;
use App\Database\DTOs\DatabaseInfo;
use App\Database\DTOs\Import\ImportRequest;
use App\Database\DTOs\Import\ImportResult;
use App\Database\Exceptions\DatabaseOperationException;
use App\Database\Import\SourceStream;
use App\Enums\ImportStatus;
use App\Enums\UploadStatus;
use App\Jobs\ImportDatabaseJob;
use App\Models\AuditLog;
use App\Models\ImportJob;
use App\Models\Upload;
use App\Models\User;
use App\Services\Import\UploadStore;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;

beforeEach(function () {
    $this->user = actingAsMySqlUser(User::factory()->create(['username' => 'root']), 'Secret@123');
    $this->directory = sys_get_temp_dir().'/dbnova-run-'.bin2hex(random_bytes(4));
    config(['dbnova.paths.uploads' => $this->directory, 'dbnova.paths.tmp' => $this->directory.'/tmp']);
    File::ensureDirectoryExists($this->directory.'/tmp');

    /** A checked import of an uploaded file, in the given state. */
    $this->checkedImport = function (array $attributes = []): ImportJob {
        $upload = Upload::factory()->for($this->user)->uploaded()->create(['size' => 9]);
        file_put_contents($upload->path(), 'SELECT 1;');

        return ImportJob::factory()->for($this->user)->create([
            'upload_id' => $upload->id,
            'database_name' => 'target',
            'status' => ImportStatus::Ready,
            'preflight' => ['accepted' => true, 'sourceDatabase' => 'shop', 'tables' => []],
            ...$attributes,
        ]);
    };

    /** Replace the adapter's importer with a mock. */
    $this->importer = function (): MockInterface {
        $importer = Mockery::mock(ImporterInterface::class);
        $this->mock(DatabaseAdapterInterface::class)->shouldReceive('importer')->andReturn($importer);

        return $importer;
    };

    $this->runJob = fn (ImportJob $import) => (new ImportDatabaseJob($import->id))->handle(app(DatabaseAdapterInterface::class), app(UploadStore::class));
});

afterEach(function () {
    File::deleteDirectory($this->directory);
});

describe('starting', function () {
    beforeEach(fn () => fakeDatabaseAdapter());

    test('a checked import is queued with the signed-in credentials stored encrypted', function () {
        Queue::fake();
        $import = ($this->checkedImport)();

        $this->postJson(route('imports.start', $import), ['speedMode' => true])
            ->assertOk()
            ->assertJson(['status' => 'queued'])
            ->assertJsonMissingPath('credentials');

        $import->refresh();
        expect($import->status)->toBe(ImportStatus::Queued);
        expect($import->credentials)->toBe(['username' => 'root', 'password' => 'Secret@123']);
        expect($import->getRawOriginal('credentials'))->not->toContain('Secret@123');
        expect($import->options)->toBe(['speedMode' => true, 'trustCompound' => false]);
        Queue::assertPushedOn('imports', ImportDatabaseJob::class, fn (ImportDatabaseJob $job) => $job->importJobId === $import->id);
        expect(AuditLog::query()->sole())->action->toBe('import.start')->database_name->toBe('target');
    });

    test('a dump with routines DBNova cannot check starts only after the user confirms trust', function () {
        Queue::fake();
        $import = ($this->checkedImport)(['preflight' => ['accepted' => true, 'sourceDatabase' => null, 'tables' => [], 'compoundProgramCount' => 2]]);

        $this->postJson(route('imports.start', $import))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trustCompound']);
        Queue::assertNothingPushed();

        $this->postJson(route('imports.start', $import), ['trustCompound' => true])->assertOk();
        expect($import->refresh()->options)->toBe(['speedMode' => false, 'trustCompound' => true]);
    });

    test('only a checked import that has not started can be started', function (ImportStatus $status) {
        Queue::fake();
        $import = ($this->checkedImport)(['status' => $status]);

        $this->postJson(route('imports.start', $import))->assertConflict();

        Queue::assertNothingPushed();
    })->with([ImportStatus::Pending, ImportStatus::Rejected, ImportStatus::Queued, ImportStatus::Completed]);

    test('read-only mode refuses to start an import', function () {
        Queue::fake();
        config(['dbnova.read_only' => true]);

        $this->postJson(route('imports.start', ($this->checkedImport)()))->assertForbidden();

        Queue::assertNothingPushed();
    });

    test('cancelling a queued import takes effect at once and wipes the credentials', function () {
        $import = ($this->checkedImport)(['status' => ImportStatus::Queued, 'credentials' => ['username' => 'root', 'password' => 'x']]);

        $this->postJson(route('imports.cancel', $import))->assertOk()->assertJson(['status' => 'cancelled']);

        expect($import->refresh()->credentials)->toBeNull();
    });
});

describe('the import job', function () {
    test('a successful import completes, wipes the credentials and deletes the file', function () {
        $import = ($this->checkedImport)(['status' => ImportStatus::Queued, 'credentials' => ['username' => 'root', 'password' => 'Secret@123'], 'options' => ['speedMode' => true]]);
        ($this->importer)()->shouldReceive('import')->once()->andReturnUsing(function (ImportRequest $request, SourceStream $source, callable $onProgress) use ($import) {
            expect($request->database)->toBe('target');
            expect($request->sourceDatabase)->toBe('shop');
            expect($request->speedMode)->toBeTrue();
            expect($request->trustCompoundPrograms)->toBeFalse();
            expect($request->credentials)->toBe(['username' => 'root', 'password' => 'Secret@123']);
            // The password is not kept at rest while the import runs.
            expect(ImportJob::query()->whereKey($import->id)->value('credentials'))->toBeNull();
            $onProgress(9, 9, 1);

            return new ImportResult(true, 9, 1, 2, warningCount: 1, warnings: ['Warning (Code 1265): Data truncated']);
        });

        ($this->runJob)($import);

        $import->refresh();
        expect($import->status)->toBe(ImportStatus::Completed);
        expect($import->credentials)->toBeNull();
        expect($import->bytes_streamed)->toBe(9);
        expect($import->options)->toMatchArray(['skippedStatements' => 2, 'warningCount' => 1]);
        expect($import->upload->status)->toBe(UploadStatus::Expired);
        expect(file_exists($import->upload->path()))->toBeFalse();
        expect(AuditLog::query()->sole())->action->toBe('import.completed')->succeeded->toBeTrue()->mysql_username->toBe('root');
    });

    test('a failed import keeps the file for a retry and shows the error and line', function () {
        $import = ($this->checkedImport)(['status' => ImportStatus::Queued, 'credentials' => ['username' => 'root', 'password' => 'x']]);
        ($this->importer)()->shouldReceive('import')->once()->andReturn(new ImportResult(false, 100, 3, 0, 'You have an error in your SQL syntax', 1064, 9));

        ($this->runJob)($import);

        $import->refresh();
        expect($import->status)->toBe(ImportStatus::Failed);
        expect($import->last_error)->toBe('You have an error in your SQL syntax');
        expect($import->error_line)->toBe(9);
        expect($import->credentials)->toBeNull();
        expect(file_exists($import->upload->path()))->toBeTrue();
        expect(AuditLog::query()->sole())->action->toBe('import.failed')->succeeded->toBeFalse();
    });

    test('a cancel requested while importing stops it', function () {
        $import = ($this->checkedImport)(['status' => ImportStatus::Queued, 'credentials' => ['username' => 'root', 'password' => 'x']]);
        ($this->importer)()->shouldReceive('import')->once()->andReturnUsing(function (ImportRequest $request, SourceStream $source, callable $onProgress) use ($import) {
            ImportJob::query()->whereKey($import->id)->update(['cancel_requested' => true]);
            $onProgress(5, 5, 1);

            return new ImportResult(true, 9, 1, 0);
        });

        ($this->runJob)($import);

        expect($import->refresh()->status)->toBe(ImportStatus::Cancelled);
        expect($import->credentials)->toBeNull();
    });

    test('a server that is not ready fails the import with the reason', function () {
        $import = ($this->checkedImport)(['status' => ImportStatus::Queued, 'credentials' => ['username' => 'root', 'password' => 'x']]);
        ($this->importer)()->shouldReceive('import')->andThrow(new DatabaseOperationException('The target database `target` does not exist (any more).'));

        ($this->runJob)($import);

        expect($import->refresh()->status)->toBe(ImportStatus::Failed);
        expect($import->last_error)->toContain('does not exist');
    });

    test('an import without saved credentials fails without running', function () {
        $import = ($this->checkedImport)(['status' => ImportStatus::Queued]);
        ($this->importer)()->shouldNotReceive('import');

        ($this->runJob)($import);

        expect($import->refresh()->status)->toBe(ImportStatus::Failed);
    });

    test('an import that is not queued any more is left alone', function () {
        $import = ($this->checkedImport)(['status' => ImportStatus::Cancelled]);
        ($this->importer)()->shouldNotReceive('import');

        ($this->runJob)($import);

        expect($import->refresh()->status)->toBe(ImportStatus::Cancelled);
    });

    test('a crashed worker marks the import failed and partial', function () {
        $import = ($this->checkedImport)(['status' => ImportStatus::Importing, 'credentials' => ['username' => 'root', 'password' => 'x']]);

        (new ImportDatabaseJob($import->id))->failed(new RuntimeException('worker died'));

        $import->refresh();
        expect($import->status)->toBe(ImportStatus::Failed);
        expect($import->last_error)->toContain('Data may be partially imported');
        expect($import->credentials)->toBeNull();
    });
});

describe('resetting the target', function () {
    beforeEach(function () {
        $this->schema = fakeSchemaManager();
        $this->connector = $this->mock(MySQLConnector::class);
        $this->connector->shouldReceive('serverLabel')->andReturn('127.0.0.1:3306');
    });

    test('drops and recreates the target with its collation after the name and password are confirmed', function () {
        $import = ($this->checkedImport)(['status' => ImportStatus::Failed]);
        $this->connector->shouldReceive('connect')->once()->with('root', 'Secret@123')->andReturn(new PDO('sqlite::memory:'));
        $this->schema->shouldReceive('database')->with('target')->andReturn(new DatabaseInfo('target', 'utf8mb4', 'utf8mb4_bin', 3, 100, false));
        $this->schema->shouldReceive('busySessionCount')->with('target')->andReturn(0);
        $this->schema->shouldReceive('dropDatabase')->once()->with('target');
        $this->schema->shouldReceive('createDatabase')->once()->with('target', 'utf8mb4_bin');

        $this->postJson(route('imports.reset-target', $import), ['confirmation' => 'target', 'password' => 'Secret@123'])->assertOk();

        expect($import->refresh()->options)->toBe(['targetReset' => true]);
        expect(AuditLog::query()->sole())->action->toBe('import.reset-target')->succeeded->toBeTrue();
    });

    test('is refused while MySQL still runs statements in the target or another import uses it', function (bool $busySession, ?ImportStatus $otherImport) {
        $import = ($this->checkedImport)(['status' => ImportStatus::Interrupted]);

        if ($otherImport !== null) {
            ($this->checkedImport)(['status' => $otherImport]);
        }

        $this->connector->shouldReceive('connect')->andReturn(new PDO('sqlite::memory:'));
        $this->schema->shouldReceive('database')->with('target')->andReturn(new DatabaseInfo('target', 'utf8mb4', 'utf8mb4_bin', 3, 100, false));
        $this->schema->shouldReceive('busySessionCount')->andReturn($busySession ? 1 : 0);
        $this->schema->shouldNotReceive('dropDatabase');

        $this->postJson(route('imports.reset-target', $import), ['confirmation' => 'target', 'password' => 'Secret@123'])->assertConflict();
    })->with([
        'an orphaned client is still running' => [true, null],
        'another import is running' => [false, ImportStatus::Importing],
        'another import is queued' => [false, ImportStatus::Queued],
    ]);

    test('says so when the drop worked but creating the database again failed', function () {
        $import = ($this->checkedImport)(['status' => ImportStatus::Failed]);
        $this->connector->shouldReceive('connect')->andReturn(new PDO('sqlite::memory:'));
        $this->schema->shouldReceive('database')->with('target')->andReturn(new DatabaseInfo('target', 'utf8mb4', 'utf8mb4_bin', 3, 100, false));
        $this->schema->shouldReceive('busySessionCount')->andReturn(0);
        $this->schema->shouldReceive('dropDatabase')->once();
        $this->schema->shouldReceive('createDatabase')->andThrow(new DatabaseOperationException('Your MySQL account does not have permission to do that.'));

        $this->postJson(route('imports.reset-target', $import), ['confirmation' => 'target', 'password' => 'Secret@123'])
            ->assertUnprocessable()
            ->assertJsonPath('message', fn (string $message) => str_starts_with($message, 'The database was dropped, but creating it again failed'));
    });

    test('is rate limited', function () {
        $import = ($this->checkedImport)(['status' => ImportStatus::Failed]);
        $this->connector->shouldReceive('connect')->andThrow(new PDOException('SQLSTATE[HY000] [1045] Access denied', 1045));

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson(route('imports.reset-target', $import), ['confirmation' => 'target', 'password' => 'guess'.$attempt])->assertUnprocessable();
        }

        $this->postJson(route('imports.reset-target', $import), ['confirmation' => 'target', 'password' => 'guess'])->assertTooManyRequests();
    });

    test('a mistyped name or wrong password changes nothing', function (array $payload, string $field) {
        $import = ($this->checkedImport)(['status' => ImportStatus::Failed]);
        $this->connector->shouldReceive('connect')->andThrow(new PDOException('SQLSTATE[HY000] [1045] Access denied', 1045));
        $this->schema->shouldNotReceive('dropDatabase');

        $this->postJson(route('imports.reset-target', $import), $payload)->assertUnprocessable()->assertJsonValidationErrors([$field]);
    })->with([
        'mistyped name' => [['confirmation' => 'targ', 'password' => 'Secret@123'], 'confirmation'],
        'wrong password' => [['confirmation' => 'target', 'password' => 'wrong'], 'password'],
    ]);

    test('only the target of an import that did not complete can be reset', function () {
        $import = ($this->checkedImport)(['status' => ImportStatus::Completed]);
        $this->connector->shouldReceive('connect')->andReturn(new PDO('sqlite::memory:'));
        $this->schema->shouldNotReceive('dropDatabase');

        $this->postJson(route('imports.reset-target', $import), ['confirmation' => 'target', 'password' => 'Secret@123'])->assertConflict();
    });
});

describe('maintenance commands', function () {
    test('imports whose heartbeat stopped are marked interrupted, checks are marked failed', function () {
        $this->freezeTime();
        $stalled = ($this->checkedImport)(['status' => ImportStatus::Importing, 'heartbeat_at' => now()->subMinutes(5), 'credentials' => ['username' => 'root', 'password' => 'x']]);
        $stalledCheck = ($this->checkedImport)(['status' => ImportStatus::Preflight, 'heartbeat_at' => now()->subMinutes(5)]);
        $healthy = ($this->checkedImport)(['status' => ImportStatus::Importing, 'heartbeat_at' => now()->subSeconds(10)]);

        $this->artisan('dbnova:recover-jobs')->assertSuccessful();

        expect($stalled->refresh()->status)->toBe(ImportStatus::Interrupted);
        expect($stalled->last_error)->toContain('Data may be partially imported');
        expect($stalled->credentials)->toBeNull();
        expect($stalledCheck->refresh()->status)->toBe(ImportStatus::Failed);
        expect($healthy->refresh()->status)->toBe(ImportStatus::Importing);
        expect(AuditLog::query()->sole())->action->toBe('import.interrupted');
    });

    test('a queued import no worker picks up gives up and wipes the saved credentials', function () {
        config(['session.lifetime' => 120]);
        $this->travelTo(now()->subHours(3));
        $abandoned = ($this->checkedImport)(['status' => ImportStatus::Queued, 'credentials' => ['username' => 'root', 'password' => 'x']]);
        $this->travelBack();
        $recent = ($this->checkedImport)(['status' => ImportStatus::Queued, 'credentials' => ['username' => 'root', 'password' => 'x']]);

        $this->artisan('dbnova:recover-jobs')->assertSuccessful();

        expect($abandoned->refresh()->status)->toBe(ImportStatus::Failed);
        expect($abandoned->credentials)->toBeNull();
        expect($abandoned->last_error)->toContain('Nothing was sent to MySQL');
        expect($recent->refresh()->status)->toBe(ImportStatus::Queued);
    });

    test('expired uploads and leftover option files are deleted, files in use are kept', function () {
        $this->travelTo(now()->subDays(2));
        $abandoned = Upload::factory()->for($this->user)->create();
        touch($abandoned->path());
        $failed = ($this->checkedImport)(['status' => ImportStatus::Failed])->upload;
        $readyButOld = ($this->checkedImport)();
        $inUse = ($this->checkedImport)(['status' => ImportStatus::Importing])->upload;
        $this->travelBack();
        $fresh = ($this->checkedImport)(['status' => ImportStatus::Failed])->upload;
        touch($oldOptionFile = $this->directory.'/tmp/old.cnf', time() - 7200);
        touch($newOptionFile = $this->directory.'/tmp/new.cnf');

        $this->artisan('dbnova:prune-temp')->assertSuccessful();

        foreach ([$abandoned, $failed, $readyButOld->upload] as $upload) {
            expect($upload->refresh()->status)->toBe(UploadStatus::Expired);
            expect(file_exists($upload->path()))->toBeFalse();
        }

        expect($readyButOld->refresh()->status)->toBe(ImportStatus::Cancelled);
        expect($inUse->refresh()->status)->toBe(UploadStatus::Uploaded);
        expect($fresh->refresh()->status)->toBe(UploadStatus::Uploaded);
        expect(file_exists($oldOptionFile))->toBeFalse();
        expect(file_exists($newOptionFile))->toBeTrue();
    });
});

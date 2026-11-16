<?php

use App\Database\Contracts\DatabaseAdapterInterface;
use App\Enums\ExportFormat;
use App\Enums\ExportStatus;
use App\Jobs\ExportDatabaseJob;
use App\Models\ExportJob;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;

/**
 * Recovery drill: the disk fills up during an upload and during an export.
 * Opt-in: point DBNOVA_DRILL_SMALL_VOLUME at an empty, small volume (e.g. a
 * 64 MB RAM disk: see PERFORMANCE.md), with the MySQL test account set.
 */
beforeEach(function () {
    $this->volume = env('DBNOVA_DRILL_SMALL_VOLUME');

    if (! is_string($this->volume) || ! is_dir($this->volume)) {
        $this->markTestSkipped('Set DBNOVA_DRILL_SMALL_VOLUME to a small, empty volume to run the disk-full drill.');
    }

    $this->work = $this->volume.'/dbnova-'.bin2hex(random_bytes(3));
    config([
        'dbnova.paths.uploads' => $this->work.'/uploads',
        'dbnova.paths.exports' => $this->work.'/exports',
        'dbnova.paths.tmp' => $this->work.'/tmp',
        // The drill wants the write itself to hit the full disk, not the up-front check.
        'dbnova.limits.min_free_disk_bytes' => 0,
        'dbnova.limits.upload_chunk_bytes' => 4 * 1024 * 1024,
    ]);
    $this->filler = $this->volume.'/filler.bin';

    /** Leave only about $bytes free on the volume. */
    $this->fillDiskLeaving = function (int $bytes): void {
        $free = (int) disk_free_space($this->volume);
        $handle = fopen($this->filler, 'wb');
        $block = str_repeat("\0", 1024 * 1024);

        for ($written = 0; $written < $free - $bytes - 1024 * 1024; $written += strlen($block)) {
            if (@fwrite($handle, $block) === false) {
                break;
            }
        }

        fclose($handle);
    };
})->group('drill');

afterEach(function () {
    if (isset($this->work)) {
        @unlink($this->filler);
        File::deleteDirectory($this->work);
    }
});

test('a full disk during an upload is a clean, retryable error, and the upload resumes once there is room', function () {
    actingAsMySqlUser();
    $chunk = 4 * 1024 * 1024;
    $put = fn (string $id, int $index): TestResponse => $this->call(
        'PUT',
        route('uploads.chunk', ['upload' => $id, 'index' => $index]),
        server: ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'],
        content: str_repeat(chr(65 + $index), $chunk),
    );

    $id = $this->postJson(route('uploads.store'), ['name' => 'dump.sql', 'size' => 3 * $chunk])->assertCreated()->json('id');
    $put($id, 0)->assertOk();

    ($this->fillDiskLeaving)(512 * 1024);
    $failed = $put($id, 1);

    expect($failed->status())->toBe(507)
        ->and($failed->json('message'))->toContain('disk')
        ->and($this->getJson(route('uploads.show', $id))->json('received'))->toBe([0]);

    unlink($this->filler);
    $put($id, 1)->assertOk();
    $put($id, 2)->assertOk();

    expect($this->getJson(route('uploads.show', $id))->json('status'))->toBe('uploaded');
});

test('a full disk during an export fails it and deletes the partial file', function () {
    $credentials = mySqlTestCredentials() ?? $this->markTestSkipped('Needs the MySQL test account.');
    $user = User::factory()->create(['username' => $credentials['username']]);
    $database = env('DBNOVA_DRILL_EXPORT_DATABASE', 'dbnova_test_drill1');

    ($this->fillDiskLeaving)(8 * 1024 * 1024);

    $export = ExportJob::factory()->for($user)->create([
        'database_name' => $database,
        'format' => ExportFormat::Sql,
        'options' => ['content' => 'both', 'includePrograms' => false],
        'credentials' => $credentials,
    ]);

    (new ExportDatabaseJob($export->id))->handle(app(DatabaseAdapterInterface::class));

    $export->refresh();
    expect($export->status)->toBe(ExportStatus::Failed)
        ->and($export->last_error)->toContain('disk')
        ->and(file_exists($export->path()))->toBeFalse()
        ->and(File::files($this->work.'/tmp'))->toBe([]);
});

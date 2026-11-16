<?php

use App\Enums\ImportStatus;
use App\Enums\UploadStatus;
use App\Models\ImportJob;
use App\Models\Upload;
use App\Models\User;
use App\Services\Import\UploadStore;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->user = actingAsMySqlUser();
    $this->directory = sys_get_temp_dir().'/dbnova-uploads-'.bin2hex(random_bytes(4));
    config(['dbnova.paths.uploads' => $this->directory, 'dbnova.limits.upload_chunk_bytes' => 1024, 'dbnova.limits.min_free_disk_bytes' => 0]);

    $this->putChunk = fn (string $uploadId, int $index, string $bytes): TestResponse => $this->call(
        'PUT',
        route('uploads.chunk', ['upload' => $uploadId, 'index' => $index]),
        server: ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'],
        content: $bytes,
    );
});

afterEach(function () {
    File::deleteDirectory($this->directory);
});

test('an upload is planned in chunks for an accepted file type', function (string $name, string $format) {
    $response = $this->postJson(route('uploads.store'), ['name' => $name, 'size' => 2500]);

    $response->assertCreated()->assertJson([
        'name' => $name,
        'format' => $format,
        'size' => 2500,
        'chunkSize' => 1024,
        'totalChunks' => 3,
        'status' => 'uploading',
        'received' => [],
    ]);
})->with([
    ['shop.sql', 'sql'],
    ['shop.SQL.GZ', 'gz'],
    ['shop.gz', 'gz'],
    ['backup.zip', 'zip'],
]);

test('other file types and sizes are refused', function (array $payload, string $message) {
    $this->postJson(route('uploads.store'), $payload)
        ->assertUnprocessable()
        ->assertJsonPath('message', fn (string $text) => str_contains($text, $message));
})->with([
    'text file' => [['name' => 'notes.txt', 'size' => 10], 'Only .sql, .sql.gz and .zip'],
    'too large' => [['name' => 'big.sql', 'size' => 200 * 1024 ** 3], 'must be between'],
]);

test('an upload is refused when the disk does not have room for it', function () {
    config(['dbnova.limits.min_free_disk_bytes' => PHP_INT_MAX >> 2]);

    $this->postJson(route('uploads.store'), ['name' => 'shop.sql', 'size' => 10])
        ->assertUnprocessable()
        ->assertJsonPath('message', fn (string $text) => str_starts_with($text, 'Not enough free disk space'));
});

test('chunks can arrive in any order, resume from the status, and rebuild the exact file', function () {
    $contents = random_bytes(2500);
    $upload = $this->postJson(route('uploads.store'), ['name' => 'shop.sql', 'size' => 2500])->json();

    ($this->putChunk)($upload['id'], 2, substr($contents, 2048))->assertOk()->assertJson(['status' => 'uploading', 'receivedCount' => 1]);
    ($this->putChunk)($upload['id'], 0, substr($contents, 0, 1024))->assertOk();
    // A repeated chunk (a retry after a lost response) changes nothing.
    ($this->putChunk)($upload['id'], 0, substr($contents, 0, 1024))->assertOk()->assertJson(['receivedCount' => 2]);

    $this->getJson(route('uploads.show', $upload['id']))->assertOk()->assertJson(['received' => [0, 2], 'status' => 'uploading']);

    ($this->putChunk)($upload['id'], 1, substr($contents, 1024, 1024))->assertOk()->assertJson(['status' => 'uploaded']);

    $stored = Upload::query()->findOrFail($upload['id']);
    expect($stored->status)->toBe(UploadStatus::Uploaded);
    expect(file_get_contents($stored->path()))->toBe($contents);
    expect(substr(sprintf('%o', fileperms($stored->path())), -4))->toBe('0600');
});

test('a chunk with the wrong length or position is refused and not recorded', function (int $index, int $length, string $message) {
    $upload = $this->postJson(route('uploads.store'), ['name' => 'shop.sql', 'size' => 2500])->json();

    ($this->putChunk)($upload['id'], $index, str_repeat('x', $length))
        ->assertUnprocessable()
        ->assertJsonPath('message', fn (string $text) => str_contains($text, $message));

    $this->getJson(route('uploads.show', $upload['id']))->assertJson(['received' => []]);
})->with([
    'short chunk' => [0, 1000, 'should be 1024 bytes but 1000 arrived'],
    'long last chunk' => [2, 600, 'should be 452 bytes'],
    'past the end' => [3, 10, 'outside the file'],
]);

test('an oversized chunk never overwrites the next chunk', function () {
    $contents = random_bytes(2500);
    $upload = $this->postJson(route('uploads.store'), ['name' => 'shop.sql', 'size' => 2500])->json();
    ($this->putChunk)($upload['id'], 1, substr($contents, 1024, 1024))->assertOk();

    ($this->putChunk)($upload['id'], 0, str_repeat('x', 1025))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Chunk 0 should be 1024 bytes but more arrived. It will be sent again.');

    ($this->putChunk)($upload['id'], 0, substr($contents, 0, 1024))->assertOk();
    ($this->putChunk)($upload['id'], 2, substr($contents, 2048))->assertOk()->assertJson(['status' => 'uploaded']);

    expect(file_get_contents(Upload::query()->findOrFail($upload['id'])->path()))->toBe($contents);
});

test('a chunk for an upload whose file is gone is refused instead of re-creating the file', function () {
    $upload = $this->postJson(route('uploads.store'), ['name' => 'shop.sql', 'size' => 2500])->json();
    $path = Upload::query()->findOrFail($upload['id'])->path();
    unlink($path);

    ($this->putChunk)($upload['id'], 0, str_repeat('x', 1024))->assertUnprocessable();

    expect(file_exists($path))->toBeFalse();
});

test('a user can have only a few unfinished uploads at once', function () {
    Upload::factory()->for($this->user)->count(UploadStore::MAX_OPEN_UPLOADS)->create();

    $this->postJson(route('uploads.store'), ['name' => 'shop.sql', 'size' => 10])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'You already have 3 unfinished uploads. Finish or cancel one first.');
});

test('a finished or cancelled upload accepts no more chunks', function () {
    $upload = Upload::factory()->for($this->user)->uploaded()->create();

    ($this->putChunk)($upload->id, 0, str_repeat('x', 1024))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'This upload is no longer accepting data.');
});

test('another user\'s upload cannot be seen, written or cancelled', function () {
    $upload = Upload::factory()->for(User::factory())->create();

    $this->getJson(route('uploads.show', $upload))->assertNotFound();
    ($this->putChunk)($upload->id, 0, 'x')->assertNotFound();
    $this->deleteJson(route('uploads.destroy', $upload))->assertNotFound();
});

test('cancelling an upload deletes its data', function () {
    $upload = $this->postJson(route('uploads.store'), ['name' => 'shop.sql', 'size' => 2500])->json();
    ($this->putChunk)($upload['id'], 0, str_repeat('x', 1024));
    $stored = Upload::query()->findOrFail($upload['id']);

    $this->deleteJson(route('uploads.destroy', $upload['id']))->assertOk()->assertJson(['status' => 'cancelled']);

    expect(file_exists($stored->path()))->toBeFalse();
    expect(is_dir($stored->chunkDirectory()))->toBeFalse();
});

test('an upload an active import is using cannot be cancelled', function () {
    $upload = Upload::factory()->for($this->user)->uploaded()->create();
    ImportJob::factory()->for($this->user)->create(['upload_id' => $upload->id, 'status' => ImportStatus::Preflight]);

    $this->deleteJson(route('uploads.destroy', $upload))->assertConflict();
});

test('uploads need a signed-in user', function () {
    auth()->logout();

    $this->postJson(route('uploads.store'), ['name' => 'shop.sql', 'size' => 10])->assertUnauthorized();
});

test('hostile file names are metadata only: the file lands under a UUID path', function (string $name) {
    $response = $this->post(route('uploads.store'), ['name' => $name, 'size' => 10], ['Accept' => 'application/json']);

    expect($response->status())->toBeLessThan(500);

    if ($response->status() !== 201) {
        return; // Refused, e.g. not a .sql file.
    }

    $upload = Upload::query()->sole();
    $path = $upload->path();

    expect($path)->toStartWith($this->directory.'/')
        ->and(basename(dirname($path)).basename($path))->not->toContain('..')
        ->and($path)->not->toContain('evil');
})->with([
    'traversal' => ['../../../../tmp/evil.sql'],
    'absolute' => ['/etc/evil.sql'],
    'windows traversal' => ['..\\..\\evil.sql'],
    'NUL byte' => ["evil.sql\0.txt"],
    'very long' => [str_repeat('a', 5000).'.sql'],
    'only an extension' => ['.sql'],
    'unicode' => ['dump_😀_ümlaut.sql'],
    'invalid UTF-8' => ["dump\xff.sql"],
]);

test('a chunk cut off by a dropped connection is not recorded, and resending it restores the exact file', function () {
    $contents = random_bytes(2500);
    $upload = $this->postJson(route('uploads.store'), ['name' => 'shop.sql', 'size' => 2500])->json();

    ($this->putChunk)($upload['id'], 0, substr($contents, 0, 1024))->assertOk();
    // The network drops 300 bytes into chunk 1: the server sees a short body.
    ($this->putChunk)($upload['id'], 1, substr($contents, 1024, 300))->assertUnprocessable();
    $this->getJson(route('uploads.show', $upload['id']))->assertJson(['received' => [0]]);

    // The browser reconnects, asks what arrived, and sends the rest.
    ($this->putChunk)($upload['id'], 1, substr($contents, 1024, 1024))->assertOk();
    ($this->putChunk)($upload['id'], 2, substr($contents, 2048))->assertOk()->assertJson(['status' => 'uploaded']);

    expect(file_get_contents(Upload::query()->findOrFail($upload['id'])->path()))->toBe($contents);
});

<?php

use App\Database\Import\SourceException;
use App\Database\Import\SourceStream;

beforeEach(function () {
    $this->directory = sys_get_temp_dir().'/dbnova-source-'.bin2hex(random_bytes(4));
    mkdir($this->directory);
    $this->sql = file_get_contents(__DIR__.'/../../../Fixtures/dumps/single-db.sql');
});

afterEach(function () {
    array_map('unlink', glob($this->directory.'/*') ?: []);
    rmdir($this->directory);
});

/**
 * Read a source to the end in small pieces.
 */
function readAll(SourceStream $source, int $pieceBytes = 100): string
{
    $text = '';

    while (! $source->eof()) {
        $text .= $source->read($pieceBytes);
    }

    return $text;
}

/**
 * @param  array<string, string>  $entries
 */
function makeZip(string $path, array $entries): void
{
    if ($entries === []) {
        // ZipArchive writes nothing for an empty archive; this is its end-of-central-directory record.
        file_put_contents($path, "PK\x05\x06".str_repeat("\0", 18));

        return;
    }

    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    foreach ($entries as $name => $contents) {
        $zip->addFromString($name, $contents);
    }

    $zip->close();
}

test('a plain .sql file is read as is, with progress in bytes', function () {
    file_put_contents($path = $this->directory.'/dump.sql', $this->sql);
    $source = SourceStream::open($path, 'sql');

    expect(readAll($source))->toBe($this->sql);
    expect($source->position())->toBe(strlen($this->sql));
    expect($source->total())->toBe(strlen($this->sql));
});

test('a .gz file is inflated incrementally, with progress in compressed bytes', function () {
    file_put_contents($path = $this->directory.'/dump.sql.gz', gzencode($this->sql));
    $source = SourceStream::open($path, 'gz');

    expect(readAll($source, 37))->toBe($this->sql);
    expect($source->position())->toBe(filesize($path));
});

test('a multi-member .gz file (concatenated gzip) is read completely', function () {
    $half = intdiv(strlen($this->sql), 2);
    file_put_contents($path = $this->directory.'/dump.sql.gz', gzencode(substr($this->sql, 0, $half)).gzencode(substr($this->sql, $half)));

    expect(readAll(SourceStream::open($path, 'gz')))->toBe($this->sql);
});

test('highly compressible .gz data is inflated without holding it all in memory', function () {
    // 64 MB of one repeated line compresses to about 64 KB.
    $line = "INSERT INTO t VALUES (1,'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');\n";
    $gz = gzopen($path = $this->directory.'/big.sql.gz', 'wb9');

    for ($index = 0; $index < 64; $index++) {
        gzwrite($gz, str_repeat($line, intdiv(1024 * 1024, strlen($line))));
    }

    gzclose($gz);

    $source = SourceStream::open($path, 'gz');
    $before = memory_get_usage();
    $peak = 0;
    $total = 0;

    while (($data = $source->read(1024 * 1024)) !== '') {
        $total += strlen($data);
        $peak = max($peak, memory_get_usage() - $before);
    }

    expect($total)->toBeGreaterThan(60 * 1024 * 1024);
    expect($peak)->toBeLessThan(24 * 1024 * 1024);
});

test('a truncated .gz file is reported, not silently cut short', function () {
    $compressed = gzencode(str_repeat($this->sql, 50));
    file_put_contents($path = $this->directory.'/dump.sql.gz', substr($compressed, 0, intdiv(strlen($compressed), 2)));

    expect(fn () => readAll(SourceStream::open($path, 'gz'), 4096))->toThrow(SourceException::class, 'truncated');
});

test('a file named .gz that is not gzip is refused', function () {
    file_put_contents($path = $this->directory.'/dump.sql.gz', $this->sql);

    expect(fn () => SourceStream::open($path, 'gz'))->toThrow(SourceException::class, 'not gzip-compressed');
});

test('the single .sql entry of a zip archive is streamed, ignoring macOS metadata', function () {
    makeZip($path = $this->directory.'/dump.zip', [
        'backup/shop.sql' => $this->sql,
        'backup/' => '',
        '__MACOSX/backup/._shop.sql' => 'metadata',
    ]);
    $source = SourceStream::open($path, 'zip');

    expect(readAll($source))->toBe($this->sql);
    expect($source->total())->toBe(strlen($this->sql));
});

test('unsafe or ambiguous zip archives are refused', function (array $entries, string $message) {
    makeZip($path = $this->directory.'/dump.zip', $entries);

    expect(fn () => SourceStream::open($path, 'zip'))->toThrow(SourceException::class, $message);
})->with([
    'path traversal' => [['../../etc/evil.sql' => 'SELECT 1;'], 'unsafe path'],
    'nested traversal' => [['dumps/../../evil.sql' => 'SELECT 1;'], 'unsafe path'],
    'absolute path' => [['/tmp/evil.sql' => 'SELECT 1;'], 'unsafe path'],
    'windows drive path' => [['C:/evil.sql' => 'SELECT 1;'], 'unsafe path'],
    'backslash traversal' => [['dumps\\..\\..\\evil.sql' => 'SELECT 1;'], 'unsafe path'],
    'leading backslash' => [['\\evil.sql' => 'SELECT 1;'], 'unsafe path'],
    'two sql files' => [['a.sql' => 'SELECT 1;', 'b.sql' => 'SELECT 2;'], 'exactly one .sql file'],
    'no sql file' => [['readme.txt' => 'hello'], 'exactly one .sql file'],
    'empty archive' => [[], 'exactly one .sql file'],
]);

test('a zip archive with very many entries is refused before its directory is loaded', function () {
    $entries = [];

    for ($index = 0; $index <= 1000; $index++) {
        $entries["f{$index}.txt"] = 'x';
    }

    makeZip($path = $this->directory.'/dump.zip', $entries);

    expect(fn () => SourceStream::open($path, 'zip'))->toThrow(SourceException::class, 'contains 1001 entries');
});

test('a zip entry that is a symbolic link is refused', function () {
    makeZip($path = $this->directory.'/dump.zip', ['dump.sql' => '/etc/passwd']);
    $zip = new ZipArchive;
    $zip->open($path);
    $zip->setExternalAttributesName('dump.sql', ZipArchive::OPSYS_UNIX, 0120777 << 16);
    $zip->close();

    expect(fn () => SourceStream::open($path, 'zip'))->toThrow(SourceException::class, 'symbolic link');
});

test('a file that is not a zip archive is refused', function () {
    file_put_contents($path = $this->directory.'/dump.zip', $this->sql);

    expect(fn () => SourceStream::open($path, 'zip'))->toThrow(SourceException::class, 'not a readable zip archive');
});

test('a missing file is reported', function () {
    expect(fn () => SourceStream::open($this->directory.'/gone.sql', 'sql'))->toThrow(SourceException::class, 'no longer on disk');
});

test('a password-protected zip is refused', function () {
    $zip = new ZipArchive;
    $zip->open($path = $this->directory.'/locked.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('dump.sql', 'SELECT 1;');

    if (! $zip->setEncryptionName('dump.sql', ZipArchive::EM_AES_256, 'secret')) {
        $this->markTestSkipped('This libzip cannot write encrypted entries.');
    }

    $zip->close();

    expect(fn () => SourceStream::open($path, 'zip'))->toThrow(SourceException::class, 'password-protected');
});

test('a zip bomb is streamed with bounded memory, never extracted', function () {
    // 64 MB of one repeated line deflates to well under 1 MB.
    $line = "INSERT INTO t VALUES (1,'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');\n";
    makeZip($path = $this->directory.'/bomb.zip', ['dump.sql' => str_repeat($line, intdiv(64 * 1024 * 1024, strlen($line)))]);
    expect(filesize($path))->toBeLessThan(1024 * 1024);

    $source = SourceStream::open($path, 'zip');
    $before = memory_get_usage();
    $peak = 0;
    $total = 0;

    while (($data = $source->read(1024 * 1024)) !== '') {
        $total += strlen($data);
        $peak = max($peak, memory_get_usage() - $before);
    }

    expect($total)->toBeGreaterThan(60 * 1024 * 1024)
        ->and($peak)->toBeLessThan(24 * 1024 * 1024)
        ->and(glob($this->directory.'/*'))->toBe([$path]);
});

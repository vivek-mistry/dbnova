<?php

use App\Database\Exceptions\DatabaseOperationException;
use App\Database\Processes\DefaultsFile;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->temporaryDirectory = sys_get_temp_dir().'/dbnova-defaults-'.uniqid();
    config([
        'dbnova.paths.tmp' => $this->temporaryDirectory,
        'dbnova.connection.host' => '127.0.0.1',
        'dbnova.connection.port' => 3306,
        'dbnova.connection.socket' => null,
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->temporaryDirectory);
});

test('credentials are written to a file only its owner can read', function () {
    $defaultsFile = DefaultsFile::create(['username' => 'root', 'password' => 'Secret@123']);

    expect(fileperms($defaultsFile->path) & 0777)->toBe(0600);
    expect(file_get_contents($defaultsFile->path))->toBe(implode("\n", [
        '[client]',
        'user="root"',
        'password="Secret@123"',
        'host="127.0.0.1"',
        'port=3306',
    ])."\n");
});

test('passwords with quotes, backslashes and comment characters are escaped', function () {
    $defaultsFile = DefaultsFile::create(['username' => 'root', 'password' => 'p"a\\s#s;w']);

    expect(file_get_contents($defaultsFile->path))->toContain('password="p\\"a\\\\s#s;w"');
});

test('a configured socket replaces the host and port', function () {
    config(['dbnova.connection.socket' => '/tmp/mysql.sock']);

    $defaultsFile = DefaultsFile::create(['username' => 'root', 'password' => 'Secret@123']);

    expect(file_get_contents($defaultsFile->path))
        ->toContain('socket="/tmp/mysql.sock"')
        ->not->toContain('host=');
});

test('deleting the file removes it from disk', function () {
    $defaultsFile = DefaultsFile::create(['username' => 'root', 'password' => 'Secret@123']);

    $defaultsFile->delete();

    expect($defaultsFile->path)->not->toBeFile();
});

test('a line break or NUL in a value is refused, because it would start a new option such as result-file', function (array $credentials, ?string $socket) {
    config(['dbnova.connection.socket' => $socket]);

    expect(fn () => DefaultsFile::create($credentials))
        ->toThrow(DatabaseOperationException::class, 'line break');

    expect(File::glob($this->temporaryDirectory.'/*.cnf'))->toBe([]);
})->with([
    'LF in password' => [['username' => 'u', 'password' => "x\nresult-file=/tmp/owned\n#"], null],
    'CR in password' => [['username' => 'u', 'password' => "x\rplugin-dir=/tmp"], null],
    'NUL in password' => [['username' => 'u', 'password' => "x\0y"], null],
    'LF in username' => [['username' => "u\nhost=evil", 'password' => 'p'], null],
    'LF in socket' => [['username' => 'u', 'password' => 'p'], "/tmp/s.sock\nprotocol=TCP"],
]);

test('the refusal never repeats the value', function () {
    try {
        DefaultsFile::create(['username' => 'u', 'password' => "Secret\nresult-file=/x"]);
    } catch (DatabaseOperationException $exception) {
        expect($exception->getMessage())->not->toContain('Secret')->not->toContain('result-file');

        return;
    }

    $this->fail('No exception was thrown.');
});

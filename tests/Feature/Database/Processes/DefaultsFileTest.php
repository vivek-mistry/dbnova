<?php

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

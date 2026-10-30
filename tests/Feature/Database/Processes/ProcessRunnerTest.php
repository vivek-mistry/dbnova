<?php

use App\Database\Processes\ProcessRunner;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->temporaryDirectory = sys_get_temp_dir().'/dbnova-runner-'.uniqid();
    config([
        'dbnova.paths.tmp' => $this->temporaryDirectory,
        'dbnova.binaries.mysqldump' => '/usr/local/mysql/bin/mysqldump',
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->temporaryDirectory);
});

test('the binary runs with a defaults file and array arguments, never a password in argv', function () {
    Process::fake();

    app(ProcessRunner::class)->run('mysqldump', ['username' => 'root', 'password' => 'Secret@123'], ['--no-data', 'shop']);

    Process::assertRan(function (PendingProcess $process) {
        return is_array($process->command)
            && $process->command[0] === '/usr/local/mysql/bin/mysqldump'
            && str_starts_with($process->command[1], '--defaults-extra-file='.$this->temporaryDirectory.'/')
            && array_slice($process->command, 2) === ['--no-data', 'shop']
            && ! str_contains(implode(' ', $process->command), 'Secret@123');
    });
});

test('the defaults file is deleted after the process finishes', function () {
    Process::fake(['*' => Process::result(errorOutput: 'Access denied', exitCode: 1)]);

    $result = app(ProcessRunner::class)->run('mysqldump', ['username' => 'root', 'password' => 'Secret@123'], ['shop']);

    expect($result->failed())->toBeTrue();
    expect(File::files($this->temporaryDirectory))->toBe([]);
});

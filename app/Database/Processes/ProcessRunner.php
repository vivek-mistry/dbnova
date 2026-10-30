<?php

namespace App\Database\Processes;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

/**
 * Runs the MySQL CLI tools with array arguments and a temporary defaults
 * file for credentials. Never builds shell command strings.
 */
class ProcessRunner
{
    /**
     * @param  'mysql'|'mysqldump'  $binary
     * @param  array{username: string, password: string}  $credentials
     * @param  list<string>  $arguments
     */
    public function run(string $binary, array $credentials, array $arguments, ?int $timeoutSeconds = 60): ProcessResult
    {
        $defaultsFile = DefaultsFile::create($credentials);

        try {
            $pendingProcess = $timeoutSeconds === null ? Process::forever() : Process::timeout($timeoutSeconds);

            return $pendingProcess->run([
                config()->string("dbnova.binaries.{$binary}"),
                '--defaults-extra-file='.$defaultsFile->path,
                ...$arguments,
            ]);
        } finally {
            $defaultsFile->delete();
        }
    }
}

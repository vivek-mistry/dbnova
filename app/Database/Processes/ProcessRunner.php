<?php

namespace App\Database\Processes;

use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Process as SymfonyProcess;
use Throwable;
use Traversable;

/**
 * Runs the MySQL CLI tools with array arguments and a temporary defaults
 * file for credentials. Never builds shell command strings.
 */
class ProcessRunner
{
    /** How often stream() services the pipes and calls $onTick. */
    private const int POLL_MICROSECONDS = 1000;

    /** How long a stopped tool gets to exit after SIGTERM before SIGKILL. */
    private const int STOP_GRACE_SECONDS = 5;

    /** How much of a tool's stderr streamOutput() keeps. */
    private const int KEPT_ERROR_BYTES = 65536;

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

    /**
     * Run a CLI tool with streamed stdin and wait for it to exit.
     *
     * $input is pulled one item at a time, only when the previous item has
     * been written to the pipe, so a producer that reads a 10 GB file never
     * gets ahead of the tool (backpressure). $onTick runs about every
     * millisecond while the tool runs, whether or not it is reading input,
     * so heartbeats and cancellation keep working during a long statement.
     * If $onTick or $input throws, the tool is stopped (SIGTERM, then
     * SIGKILL after 5 s) and the exception is rethrown.
     *
     * @param  'mysql'|'mysqldump'  $binary
     * @param  array{username: string, password: string}  $credentials
     * @param  list<string>  $arguments
     * @param  Traversable<int, string>  $input
     * @param  callable(InvokedProcess): void  $onTick
     */
    public function stream(string $binary, array $credentials, array $arguments, Traversable $input, callable $onTick): ProcessResult
    {
        $defaultsFile = DefaultsFile::create($credentials);
        $process = null;

        try {
            $process = Process::forever()->input($input)->start([
                config()->string("dbnova.binaries.{$binary}"),
                '--defaults-extra-file='.$defaultsFile->path,
                ...$arguments,
            ]);

            // running() also moves input into the pipe and output out of it.
            while ($process->running()) {
                $onTick($process);
                usleep(self::POLL_MICROSECONDS);
            }

            return $process->wait();
        } catch (Throwable $exception) {
            if ($process !== null) {
                $this->terminate($process);
            }

            throw $exception;
        } finally {
            $defaultsFile->delete();
        }
    }

    /**
     * Run a CLI tool and hand its stdout to $onOutput piece by piece, so a
     * multi-GB dump never sits in memory or in a second temporary file.
     *
     * Symfony Process is used directly because it can drop output that was
     * already handed over (clearOutput); the Process facade keeps it all.
     * When the tool produces output faster than $onOutput consumes it, the
     * pipe fills and the tool waits (backpressure). $onTick runs at least
     * every few milliseconds, also while the tool is quiet. If either
     * callback throws, the tool is stopped (SIGTERM, then SIGKILL after
     * 5 s) and the exception is rethrown.
     *
     * @param  'mysql'|'mysqldump'  $binary
     * @param  array{username: string, password: string}  $credentials
     * @param  list<string>  $arguments
     * @param  callable(string): void  $onOutput
     * @param  callable(): void  $onTick
     * @return array{exitCode: int, errorOutput: string} The last 64 KB of stderr.
     */
    public function streamOutput(string $binary, array $credentials, array $arguments, callable $onOutput, callable $onTick): array
    {
        $defaultsFile = DefaultsFile::create($credentials);
        $process = new SymfonyProcess([
            config()->string("dbnova.binaries.{$binary}"),
            '--defaults-extra-file='.$defaultsFile->path,
            ...$arguments,
        ], timeout: null);
        $errorOutput = '';

        try {
            $process->start();

            do {
                $running = $process->isRunning();
                $received = $this->drainOutput($process, $onOutput, $errorOutput);
                $onTick();

                if ($running && $received === 0) {
                    usleep(self::POLL_MICROSECONDS);
                }
            } while ($running);

            return ['exitCode' => $process->getExitCode() ?? -1, 'errorOutput' => $errorOutput];
        } catch (Throwable $exception) {
            $this->stop($process);

            throw $exception;
        } finally {
            $defaultsFile->delete();
        }
    }

    /**
     * SIGTERM, then SIGKILL if the tool has not exited after a grace period.
     */
    private function stop(SymfonyProcess $process): void
    {
        if (! $process->isRunning()) {
            return;
        }

        $process->stop(self::STOP_GRACE_SECONDS, SIGKILL);
    }

    /**
     * Hand new stdout to $onOutput and forget it; keep the tail of stderr.
     *
     * @param  callable(string): void  $onOutput
     */
    private function drainOutput(SymfonyProcess $process, callable $onOutput, string &$errorOutput): int
    {
        $output = $process->getIncrementalOutput();
        $process->clearOutput();

        $error = $process->getIncrementalErrorOutput();

        if ($error !== '') {
            $process->clearErrorOutput();
            $errorOutput = substr($errorOutput.$error, -self::KEPT_ERROR_BYTES);
        }

        if ($output !== '') {
            $onOutput($output);
        }

        return strlen($output);
    }

    /**
     * SIGTERM, then SIGKILL if the tool has not exited after a grace period.
     */
    private function terminate(InvokedProcess $process): void
    {
        if (! $process->running()) {
            return;
        }

        $process->signal(SIGTERM);
        $deadline = microtime(true) + self::STOP_GRACE_SECONDS;

        while ($process->running() && microtime(true) < $deadline) {
            usleep(50_000);
        }

        if ($process->running()) {
            $process->signal(SIGKILL);
        }
    }
}

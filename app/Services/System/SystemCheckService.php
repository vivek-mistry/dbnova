<?php

namespace App\Services\System;

use App\Database\Adapters\MySQL\MySQLConnector;
use App\Jobs\RecordQueueHeartbeat;
use App\Services\Auth\MySQLCredentialStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Number;
use PDOException;

class SystemCheckService
{
    public function __construct(
        private MySQLConnector $connector,
        private MySQLCredentialStore $credentials,
    ) {}

    public const string MINIMUM_PHP_VERSION = '8.3.0';

    /**
     * Extensions the web application cannot run without.
     *
     * @var list<string>
     */
    public const array REQUIRED_EXTENSIONS = ['pdo_sqlite', 'pdo_mysql', 'mbstring', 'openssl', 'zip', 'zlib', 'fileinfo'];

    /**
     * Extensions the queue worker needs to stop and cancel long-running imports.
     *
     * @var list<string>
     */
    public const array WORKER_EXTENSIONS = ['pcntl', 'posix'];

    /**
     * Run every system check.
     *
     * @return list<SystemCheck>
     */
    public function run(): array
    {
        return [
            $this->checkPhpVersion(),
            $this->checkRequiredExtensions(),
            $this->checkWorkerExtensions(),
            $this->checkBinary('mysql', 'DBNOVA_MYSQL_BIN'),
            $this->checkBinary('mysqldump', 'DBNOVA_MYSQLDUMP_BIN'),
            $this->checkMySqlServer(),
            $this->checkStoragePaths(),
            $this->checkFreeDiskSpace(),
            $this->checkQueueWorker(),
        ];
    }

    /**
     * Determine whether any check failed.
     *
     * @param  list<SystemCheck>  $checks
     */
    public function hasFailures(array $checks): bool
    {
        return collect($checks)->contains(fn (SystemCheck $check): bool => $check->status === SystemCheckStatus::Failed);
    }

    public function checkPhpVersion(): SystemCheck
    {
        if (version_compare(PHP_VERSION, self::MINIMUM_PHP_VERSION, '<')) {
            return SystemCheck::failed('PHP version', 'PHP '.PHP_VERSION, 'DBNova requires PHP '.self::MINIMUM_PHP_VERSION.' or newer.');
        }

        return SystemCheck::ok('PHP version', 'PHP '.PHP_VERSION);
    }

    public function checkRequiredExtensions(): SystemCheck
    {
        $missingExtensions = $this->missingExtensions(self::REQUIRED_EXTENSIONS);

        if ($missingExtensions !== []) {
            return SystemCheck::failed(
                'PHP extensions',
                'Missing: '.implode(', ', $missingExtensions),
                'Install and enable the missing PHP extensions.',
            );
        }

        return SystemCheck::ok('PHP extensions', implode(', ', self::REQUIRED_EXTENSIONS));
    }

    public function checkWorkerExtensions(): SystemCheck
    {
        $missingExtensions = $this->missingExtensions(self::WORKER_EXTENSIONS);

        if ($missingExtensions !== []) {
            return SystemCheck::warning(
                'Worker extensions',
                'Missing in this PHP runtime: '.implode(', ', $missingExtensions),
                'The queue worker (PHP CLI) needs these to cancel imports. Web servers often lack them, which is fine.',
            );
        }

        return SystemCheck::ok('Worker extensions', implode(', ', self::WORKER_EXTENSIONS));
    }

    public function checkBinary(string $binary, string $environmentKey): SystemCheck
    {
        $name = "{$binary} client";
        $path = (string) config("dbnova.binaries.{$binary}");
        $hint = "Set {$environmentKey} in .env to the absolute path of the {$binary} executable.";

        if ($path === '' || ! is_file($path) || ! is_executable($path)) {
            return SystemCheck::failed($name, "Not found or not executable: {$path}", $hint);
        }

        $result = Process::timeout(10)->run([$path, '--version']);

        if ($result->failed()) {
            return SystemCheck::failed($name, trim($result->errorOutput()) ?: "{$path} --version failed", $hint);
        }

        return SystemCheck::ok($name, trim($result->output()));
    }

    public function checkMySqlServer(): SystemCheck
    {
        $credentials = $this->credentials->get();
        $server = $this->connector->serverLabel();

        try {
            $statement = $this->connector
                ->connect($credentials['username'] ?? '', $credentials['password'] ?? '')
                ->query('SELECT VERSION()');
        } catch (PDOException $exception) {
            if ($credentials === null && MySQLConnector::isAccessDenied($exception)) {
                return SystemCheck::ok('MySQL server', "Reachable at {$server}; sign in to read its version");
            }

            return SystemCheck::failed(
                'MySQL server',
                $exception->getMessage(),
                'Check DBNOVA_DB_HOST, DBNOVA_DB_PORT and DBNOVA_DB_SOCKET in .env and that MySQL is running.',
            );
        }

        if ($statement === false) {
            return SystemCheck::failed('MySQL server', 'Connected, but the server version could not be read.');
        }

        return SystemCheck::ok('MySQL server', "MySQL {$statement->fetchColumn()} at {$server}");
    }

    public function checkStoragePaths(): SystemCheck
    {
        $unwritablePaths = [];

        foreach (config()->array('dbnova.paths') as $path) {
            File::ensureDirectoryExists($path);

            if (! is_writable($path)) {
                $unwritablePaths[] = $path;
            }
        }

        if ($unwritablePaths !== []) {
            return SystemCheck::failed(
                'Storage paths',
                'Not writable: '.implode(', ', $unwritablePaths),
                'Give the web server and queue worker user write access to storage/app/dbnova.',
            );
        }

        return SystemCheck::ok('Storage paths', 'All working directories are writable');
    }

    public function checkFreeDiskSpace(): SystemCheck
    {
        $freeBytes = disk_free_space(storage_path('app'));
        $minimumBytes = (int) config('dbnova.limits.min_free_disk_bytes');

        if ($freeBytes === false) {
            return SystemCheck::warning('Free disk space', 'Unable to determine free disk space');
        }

        $detail = Number::fileSize($freeBytes, precision: 1).' free';

        if ($freeBytes < $minimumBytes) {
            return SystemCheck::warning(
                'Free disk space',
                $detail,
                'Imports need at least twice the size of the dump file in free space.',
            );
        }

        return SystemCheck::ok('Free disk space', $detail);
    }

    public function checkQueueWorker(): SystemCheck
    {
        $hint = 'Run `php artisan queue:work --queue=imports,exports,default` and the scheduler (`php artisan schedule:work` or cron).';
        $lastHeartbeat = Cache::get(RecordQueueHeartbeat::CACHE_KEY);

        if ($lastHeartbeat === null) {
            return SystemCheck::warning('Queue worker', 'No heartbeat recorded yet', $hint);
        }

        $secondsAgo = now()->getTimestamp() - (int) $lastHeartbeat;

        if ($secondsAgo > (int) config('dbnova.queue_heartbeat_stale_seconds')) {
            return SystemCheck::warning('Queue worker', "Last heartbeat {$secondsAgo} seconds ago", $hint);
        }

        return SystemCheck::ok('Queue worker', "Last heartbeat {$secondsAgo} seconds ago");
    }

    /**
     * @param  list<string>  $extensions
     * @return list<string>
     */
    private function missingExtensions(array $extensions): array
    {
        return array_values(array_filter($extensions, fn (string $extension): bool => ! extension_loaded($extension)));
    }
}

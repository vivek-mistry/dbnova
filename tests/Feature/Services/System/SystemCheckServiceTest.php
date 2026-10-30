<?php

use App\Database\Adapters\MySQL\MySQLConnector;
use App\Jobs\RecordQueueHeartbeat;
use App\Models\User;
use App\Services\System\SystemCheckService;
use App\Services\System\SystemCheckStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

describe('client binaries', function () {
    test('a missing binary fails with a hint naming its environment key', function () {
        Process::fake();
        config(['dbnova.binaries.mysql' => '/missing/bin/mysql']);

        $check = app(SystemCheckService::class)->checkBinary('mysql', 'DBNOVA_MYSQL_BIN');

        expect($check->status)->toBe(SystemCheckStatus::Failed);
        expect($check->detail)->toBe('Not found or not executable: /missing/bin/mysql');
        expect($check->hint)->toContain('DBNOVA_MYSQL_BIN');
        Process::assertNothingRan();
    });

    test('an executable binary reports its version using array arguments', function () {
        Process::fake(['*' => Process::result(output: "mysql  Ver 8.4.0 for macos14 on arm64\n")]);
        config(['dbnova.binaries.mysql' => PHP_BINARY]);

        $check = app(SystemCheckService::class)->checkBinary('mysql', 'DBNOVA_MYSQL_BIN');

        expect($check->status)->toBe(SystemCheckStatus::Ok);
        expect($check->detail)->toBe('mysql  Ver 8.4.0 for macos14 on arm64');
        Process::assertRan(fn ($process) => $process->command === [PHP_BINARY, '--version']);
    });

    test('a binary whose version command fails is reported as failed', function () {
        Process::fake(['*' => Process::result(errorOutput: 'library not loaded', exitCode: 1)]);
        config(['dbnova.binaries.mysqldump' => PHP_BINARY]);

        $check = app(SystemCheckService::class)->checkBinary('mysqldump', 'DBNOVA_MYSQLDUMP_BIN');

        expect($check->status)->toBe(SystemCheckStatus::Failed);
        expect($check->detail)->toBe('library not loaded');
    });
});

describe('mysql server', function () {
    test('an unreachable server fails with a hint about the connection settings', function () {
        config(['dbnova.connection.socket' => '/missing/dbnova-test-mysql.sock']);

        $check = app(SystemCheckService::class)->checkMySqlServer();

        expect($check->status)->toBe(SystemCheckStatus::Failed);
        expect($check->hint)->toContain('DBNOVA_DB_HOST');
    });

    test('a server that rejects an anonymous connection is reachable when nobody is signed in', function () {
        $this->mock(MySQLConnector::class, fn ($mock) => $mock
            ->shouldReceive('serverLabel')->andReturn('127.0.0.1:3306')
            ->shouldReceive('connect')->once()->with('', '')
            ->andThrow(new PDOException('SQLSTATE[HY000] [1045] Access denied', 1045)));

        $check = app(SystemCheckService::class)->checkMySqlServer();

        expect($check->status)->toBe(SystemCheckStatus::Ok);
        expect($check->detail)->toBe('Reachable at 127.0.0.1:3306; sign in to read its version');
    });

    test('the server version is read with the signed-in user\'s credentials', function () {
        actingAsMySqlUser(User::factory()->create(['username' => 'root']), 'Secret@123');
        $statement = Mockery::mock(PDOStatement::class);
        $statement->shouldReceive('fetchColumn')->andReturn('8.0.39');
        $pdo = Mockery::mock(PDO::class);
        $pdo->shouldReceive('query')->with('SELECT VERSION()')->andReturn($statement);
        $this->mock(MySQLConnector::class, fn ($mock) => $mock
            ->shouldReceive('serverLabel')->andReturn('127.0.0.1:3306')
            ->shouldReceive('connect')->once()->with('root', 'Secret@123')->andReturn($pdo));

        $check = app(SystemCheckService::class)->checkMySqlServer();

        expect($check->status)->toBe(SystemCheckStatus::Ok);
        expect($check->detail)->toBe('MySQL 8.0.39 at 127.0.0.1:3306');
    });
});

describe('storage paths', function () {
    beforeEach(function () {
        $this->workingDirectory = sys_get_temp_dir().'/dbnova-tests-'.uniqid();
    });

    afterEach(function () {
        File::chmod($this->workingDirectory, 0755);
        File::deleteDirectory($this->workingDirectory);
    });

    test('missing working directories are created', function () {
        config(['dbnova.paths' => ['uploads' => "{$this->workingDirectory}/uploads"]]);

        $check = app(SystemCheckService::class)->checkStoragePaths();

        expect($check->status)->toBe(SystemCheckStatus::Ok);
        expect("{$this->workingDirectory}/uploads")->toBeDirectory();
    });

    test('an unwritable working directory fails', function () {
        File::ensureDirectoryExists($this->workingDirectory);
        File::chmod($this->workingDirectory, 0555);
        config(['dbnova.paths' => ['uploads' => $this->workingDirectory]]);

        $check = app(SystemCheckService::class)->checkStoragePaths();

        expect($check->status)->toBe(SystemCheckStatus::Failed);
        expect($check->detail)->toBe("Not writable: {$this->workingDirectory}");
    });
});

test('free disk space below the configured minimum is a warning', function (int $minimumBytes, SystemCheckStatus $expectedStatus) {
    config(['dbnova.limits.min_free_disk_bytes' => $minimumBytes]);

    $check = app(SystemCheckService::class)->checkFreeDiskSpace();

    expect($check->status)->toBe($expectedStatus);
})->with([
    'below minimum' => [PHP_INT_MAX, SystemCheckStatus::Warning],
    'above minimum' => [0, SystemCheckStatus::Ok],
]);

describe('queue worker', function () {
    test('no heartbeat is a warning', function () {
        $check = app(SystemCheckService::class)->checkQueueWorker();

        expect($check->status)->toBe(SystemCheckStatus::Warning);
        expect($check->detail)->toBe('No heartbeat recorded yet');
    });

    test('a recent heartbeat is ok', function () {
        $this->freezeTime();
        Cache::forever(RecordQueueHeartbeat::CACHE_KEY, now()->subSeconds(30)->getTimestamp());

        $check = app(SystemCheckService::class)->checkQueueWorker();

        expect($check->status)->toBe(SystemCheckStatus::Ok);
        expect($check->detail)->toBe('Last heartbeat 30 seconds ago');
    });

    test('a heartbeat older than the stale threshold is a warning', function () {
        $this->freezeTime();
        config(['dbnova.queue_heartbeat_stale_seconds' => 180]);
        Cache::forever(RecordQueueHeartbeat::CACHE_KEY, now()->subSeconds(181)->getTimestamp());

        $check = app(SystemCheckService::class)->checkQueueWorker();

        expect($check->status)->toBe(SystemCheckStatus::Warning);
        expect($check->detail)->toBe('Last heartbeat 181 seconds ago');
    });
});

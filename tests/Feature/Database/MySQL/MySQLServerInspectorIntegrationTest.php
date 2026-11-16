<?php

use App\Database\Adapters\MySQL\MySQLConnection;
use App\Database\Adapters\MySQL\MySQLServerInspector;
use App\Database\DTOs\ProcessInfo;
use App\Database\Exceptions\DatabaseOperationException;
use App\Database\Exceptions\ReadOnlyModeException;
use App\Database\Processes\DefaultsFile;
use Symfony\Component\Process\Process;

/**
 * Runs against a real MySQL server when DBNOVA_TEST_DB_USERNAME is set.
 * A second client (the mysql CLI) runs a long SLEEP to be found and killed.
 */
beforeEach(function () {
    $this->credentials = mySqlTestCredentials();

    if ($this->credentials === null) {
        $this->markTestSkipped('Set DBNOVA_TEST_DB_USERNAME in .env to run MySQL integration tests.');
    }

    $this->connection = app(MySQLConnection::class)->useCredentials($this->credentials);
    $this->inspector = app(MySQLServerInspector::class);

    /** Start `SELECT SLEEP(30) /* marker *\/` on its own connection and wait until it shows up. */
    $this->startSleeper = function (): array {
        if (! is_executable(config()->string('dbnova.binaries.mysql'))) {
            $this->markTestSkipped('Set DBNOVA_MYSQL_BIN to run process tests.');
        }

        $marker = 'dbnova-test-'.bin2hex(random_bytes(4));
        $this->defaultsFile = DefaultsFile::create($this->credentials);
        $process = new Process([
            config()->string('dbnova.binaries.mysql'),
            '--defaults-extra-file='.$this->defaultsFile->path,
            '--batch',
            '-e',
            "SELECT SLEEP(30) AS `{$marker}`",
        ]);
        $process->start();
        $this->sleeper = $process;

        $deadline = microtime(true) + 10;

        while (microtime(true) < $deadline) {
            $found = collect($this->inspector->processes())->first(fn (ProcessInfo $p): bool => str_contains((string) $p->info, $marker));

            if ($found !== null) {
                return [$process, $found];
            }

            usleep(100_000);
        }

        throw new RuntimeException('The sleeping query never appeared in the process list.');
    };
})->group('mysql');

afterEach(function () {
    if (isset($this->sleeper) && $this->sleeper->isRunning()) {
        $this->sleeper->stop(0);
    }

    if (isset($this->defaultsFile)) {
        $this->defaultsFile->delete();
    }
});

test('the process list shows a running query with its SQL and time, and flags DBNova\'s own connection', function () {
    [, $found] = ($this->startSleeper)();

    expect($found->command)->toBe('Query')
        ->and($found->user)->toBe($this->credentials['username'])
        ->and($found->info)->toContain('SLEEP(30)')
        ->and($found->isCurrentConnection)->toBeFalse();

    $own = collect($this->inspector->processes())->filter(fn (ProcessInfo $p) => $p->isCurrentConnection);
    expect($own)->toHaveCount(1);
});

test('stopping the query ends the SLEEP early and leaves the client connected', function () {
    [$process, $found] = ($this->startSleeper)();
    $started = microtime(true);

    expect($this->inspector->kill($found->id))->toBeTrue();

    $process->wait();
    expect(microtime(true) - $started)->toBeLessThan(10);

    // An interrupted SLEEP returns 1 instead of 0; the client finished normally (it was not disconnected).
    expect($process->getExitCode())->toBe(0)->and($process->getOutput())->toContain('1');
});

test('killing the connection disconnects the client', function () {
    [$process, $found] = ($this->startSleeper)();

    expect($this->inspector->kill($found->id, connection: true))->toBeTrue();

    $process->wait();
    expect($process->getExitCode())->not->toBe(0);
    expect(collect($this->inspector->processes())->contains(fn (ProcessInfo $p) => $p->id === $found->id))->toBeFalse();
});

test('a thread that no longer exists reports false, and DBNova\'s own connection is refused', function () {
    expect($this->inspector->kill(999_999_999))->toBeFalse();

    expect(fn () => $this->inspector->kill($this->connection->connectionId()))
        ->toThrow(DatabaseOperationException::class, 'DBNova is using');
});

test('read-only mode blocks killing in the adapter', function () {
    config(['dbnova.read_only' => true]);

    expect(fn () => $this->inspector->kill(999_999_999))->toThrow(ReadOnlyModeException::class);
});

test('variables, status and metrics read real values', function () {
    $variables = array_column($this->inspector->variables(), 'value', 'name');
    $status = array_column($this->inspector->status(), 'value', 'name');
    $metrics = $this->inspector->metrics();

    expect((int) $variables['max_connections'])->toBeGreaterThan(0)
        ->and((int) $status['Uptime'])->toBeGreaterThan(0)
        ->and($metrics->maxConnections)->toBe((int) $variables['max_connections'])
        ->and($metrics->questions)->toBeGreaterThan(0)
        ->and($metrics->threadsConnected)->toBeGreaterThanOrEqual(1)
        ->and($metrics->bufferPoolPagesTotal)->toBeGreaterThan(0)
        ->and($metrics->bufferPoolPagesFree)->toBeLessThanOrEqual($metrics->bufferPoolPagesTotal);
});

test('global privileges are read for the signed-in account', function () {
    // Works for any test account: root (CI) holds CREATE USER, a limited account (dbnova_test) does not.
    $globalGrants = collect($this->connection->select('SHOW GRANTS'))
        ->map(fn (array $row): string => (string) reset($row))
        ->filter(fn (string $grant): bool => str_contains($grant, ' ON *.* '))
        ->implode("\n");
    $holdsCreateUser = str_contains($globalGrants, 'ALL PRIVILEGES') || str_contains($globalGrants, 'CREATE USER');

    expect($this->inspector->hasGlobalPrivilege('CREATE USER'))->toBe($holdsCreateUser)
        ->and($this->inspector->hasGlobalPrivilege('create user'))->toBe($holdsCreateUser)
        ->and($this->inspector->hasGlobalPrivilege('NOT A PRIVILEGE'))->toBeFalse();
});

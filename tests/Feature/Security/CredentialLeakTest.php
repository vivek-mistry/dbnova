<?php

use App\Actions\Fortify\AuthenticateMySQLUser;
use App\Database\Adapters\MySQL\MySQLConnection;
use App\Database\Adapters\MySQL\MySQLConnector;
use App\Database\Exceptions\DatabaseOperationException;
use App\Database\Processes\DefaultsFile;
use Monolog\Formatter\LineFormatter;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Stack traces record call arguments. A failed connect must never write the
 * MySQL password into laravel.log (security review 9, HIGH-1).
 */
beforeEach(function () {
    // Nothing listens on port 1: the connect fails at once, as during a MySQL restart.
    config(['dbnova.connection.host' => '127.0.0.1', 'dbnova.connection.port' => 1, 'dbnova.connection.socket' => null]);
});

/** Format an exception exactly as Laravel's single/daily log channels do (with stack traces). */
function loggedText(Throwable $exception): string
{
    $formatter = new LineFormatter(null, null, true, true);
    $formatter->includeStacktraces();

    return $formatter->format(new LogRecord(new DateTimeImmutable, 'local', Level::Error, $exception->getMessage(), ['exception' => $exception]));
}

function caught(callable $callback): Throwable
{
    try {
        $callback();
    } catch (Throwable $exception) {
        return $exception;
    }

    throw new RuntimeException('Expected an exception.');
}

test('a failed connect does not log the password', function () {
    $exception = caught(fn () => app(MySQLConnection::class)->useCredentials(['username' => 'leaky', 'password' => 'Pw9xQsecret'])->pdo());

    expect($exception)->toBeInstanceOf(DatabaseOperationException::class)
        ->and(loggedText($exception))->toContain('MySQLConnector')->not->toContain('Pw9xQsecret');
});

test('the connector itself hides the password from its trace', function () {
    $exception = caught(fn () => app(MySQLConnector::class)->connect('leaky', 'Pw9xQsecret'));

    expect(loggedText($exception))->not->toContain('Pw9xQsecret');
});

test('a password check at login or before a drop does not log the password', function () {
    // An unreachable server is reported as a validation error; its trace passes through verify().
    $exception = caught(fn () => app(AuthenticateMySQLUser::class)->verify('leaky', 'Pw9xQsecret'));

    expect(loggedText($exception))->not->toContain('Pw9xQsecret');
});

test('a refused option-file value is not logged', function () {
    $exception = caught(fn () => DefaultsFile::create(['username' => 'leaky', 'password' => "Pw9xQ\nsecret"]));

    expect(loggedText($exception))->not->toContain('Pw9xQ');
});

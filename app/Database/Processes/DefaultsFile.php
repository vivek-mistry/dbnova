<?php

namespace App\Database\Processes;

use App\Database\Exceptions\DatabaseOperationException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * A temporary MySQL option file (mode 0600) passed to the CLI tools with
 * --defaults-extra-file, so credentials never appear in the process list.
 */
final class DefaultsFile
{
    private function __construct(public readonly string $path) {}

    /**
     * @param  array{username: string, password: string}  $credentials
     */
    public static function create(array $credentials): self
    {
        $directory = config()->string('dbnova.paths.tmp');
        File::ensureDirectoryExists($directory, 0700);

        $path = $directory.'/'.Str::uuid().'.cnf';

        $previousUmask = umask(0077);

        try {
            file_put_contents($path, implode("\n", self::options($credentials))."\n");
        } finally {
            umask($previousUmask);
        }

        chmod($path, 0600);

        return new self($path);
    }

    public function delete(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    /**
     * @param  array{username: string, password: string}  $credentials
     * @return list<string>
     */
    private static function options(array $credentials): array
    {
        self::assertSingleLine($credentials['username'], 'username');
        self::assertSingleLine($credentials['password'], 'password');
        self::assertSingleLine((string) config('dbnova.connection.socket'), 'socket path');
        self::assertSingleLine(config()->string('dbnova.connection.host'), 'host');

        $options = [
            '[client]',
            'user='.self::quote($credentials['username']),
            'password='.self::quote($credentials['password']),
        ];

        $socket = config('dbnova.connection.socket');

        if (filled($socket)) {
            $options[] = 'socket='.self::quote((string) $socket);
        } else {
            $options[] = 'host='.self::quote(config()->string('dbnova.connection.host'));
            $options[] = 'port='.config()->integer('dbnova.connection.port');
        }

        return $options;
    }

    /**
     * Option files are read line by line, so a line break in a value would
     * start a new option (e.g. result-file=… to make mysqldump write over
     * any file the worker can write). Quoting cannot prevent that; such
     * values are refused. The message never contains the value.
     *
     * @throws DatabaseOperationException
     */
    private static function assertSingleLine(string $value, string $what): void
    {
        if (strpbrk($value, "\r\n\0") !== false) {
            throw new DatabaseOperationException(sprintf(
                'The MySQL %s contains a line break or NUL character, which the MySQL command-line tools cannot be given safely. Change it to use imports and exports.',
                $what,
            ));
        }
    }

    /**
     * Quote an option value so characters like # and ; are taken literally.
     */
    private static function quote(string $value): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }
}

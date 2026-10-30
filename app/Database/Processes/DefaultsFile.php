<?php

namespace App\Database\Processes;

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
     * Quote an option value so characters like # and ; are taken literally.
     */
    private static function quote(string $value): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }
}

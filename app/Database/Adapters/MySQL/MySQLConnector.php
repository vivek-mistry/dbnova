<?php

namespace App\Database\Adapters\MySQL;

use PDO;
use PDOException;

/**
 * Opens raw PDO connections to the managed MySQL server using the
 * host settings from config/dbnova.php and the signed-in user's account.
 */
class MySQLConnector
{
    /**
     * MySQL error codes meaning the server rejected the username or password.
     *
     * @var list<int>
     */
    public const array ACCESS_DENIED_CODES = [1045, 1698];

    /**
     * @param  array<int, mixed>  $options  Extra PDO attributes.
     *
     * @throws PDOException
     */
    public function connect(string $username, string $password, array $options = []): PDO
    {
        // PDO attributes are integer keys: array_replace keeps them, whereas `...` would renumber them.
        return new PDO($this->dsn(), $username, $password, array_replace([
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => config()->integer('dbnova.connection.connect_timeout'),
        ], $options));
    }

    /**
     * A human-readable description of where the server is, e.g. "127.0.0.1:3306".
     */
    public function serverLabel(): string
    {
        $socket = config('dbnova.connection.socket');

        if (filled($socket)) {
            return "socket {$socket}";
        }

        return config()->string('dbnova.connection.host').':'.config()->integer('dbnova.connection.port');
    }

    public static function isAccessDenied(PDOException $exception): bool
    {
        return in_array((int) $exception->getCode(), self::ACCESS_DENIED_CODES, true);
    }

    private function dsn(): string
    {
        $socket = config('dbnova.connection.socket');
        $charset = config()->string('dbnova.connection.charset');

        if (filled($socket)) {
            return "mysql:unix_socket={$socket};charset={$charset}";
        }

        $host = config()->string('dbnova.connection.host');
        $port = config()->integer('dbnova.connection.port');

        return "mysql:host={$host};port={$port};charset={$charset}";
    }
}

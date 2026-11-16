<?php

namespace App\Database\Adapters\MySQL;

use App\Database\Exceptions\DatabaseOperationException;
use App\Database\Exceptions\NotSignedInException;
use App\Services\Auth\MySQLCredentialStore;
use PDO;
use PDOException;

/**
 * The signed-in user's PDO connection to the managed MySQL server, opened
 * lazily on first use and shared for the rest of the request.
 */
class MySQLConnection
{
    /**
     * MySQL error: the thread to kill does not exist (the query already finished).
     */
    private const int UNKNOWN_THREAD = 1094;

    private ?PDO $pdo = null;

    /**
     * Credentials that override the session's, for CLI commands and queued jobs.
     *
     * @var array{username: string, password: string}|null
     */
    private ?array $explicitCredentials = null;

    public function __construct(
        private MySQLConnector $connector,
        private MySQLCredentialStore $credentials,
    ) {}

    /**
     * Connect as the given MySQL account instead of the signed-in user.
     *
     * @param  array{username: string, password: string}  $credentials
     */
    public function useCredentials(#[\SensitiveParameter] array $credentials): static
    {
        $this->explicitCredentials = $credentials;
        $this->pdo = null;

        return $this;
    }

    /**
     * @throws DatabaseOperationException
     */
    public function pdo(): PDO
    {
        return $this->pdo ??= $this->open();
    }

    /**
     * Run a query and return every row as an associative array.
     *
     * @param  list<mixed>  $bindings
     * @return list<array<string, mixed>>
     *
     * @throws DatabaseOperationException
     */
    public function select(string $sql, array $bindings = []): array
    {
        try {
            $statement = $this->pdo()->prepare($sql);
            $statement->execute($bindings);

            /** @var list<array<string, mixed>> $rows */
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

            return $rows;
        } catch (PDOException $exception) {
            throw DatabaseOperationException::fromPdoException($exception);
        }
    }

    /**
     * Run a query and return the first column of the first row.
     *
     * @param  list<mixed>  $bindings
     *
     * @throws DatabaseOperationException
     */
    public function scalar(string $sql, array $bindings = []): mixed
    {
        $row = $this->select($sql, $bindings)[0] ?? null;

        return $row === null ? null : reset($row);
    }

    /**
     * Quote a string literal for this server (respects its SQL mode).
     *
     * @throws DatabaseOperationException
     */
    public function quote(string $value): string
    {
        $quoted = $this->pdo()->quote($value);

        if ($quoted === false) {
            throw new DatabaseOperationException('The MySQL driver could not quote a value.');
        }

        return $quoted;
    }

    /**
     * Run an INSERT, UPDATE or DELETE and return the number of rows it matched.
     *
     * @param  list<mixed>  $bindings
     *
     * @throws DatabaseOperationException
     */
    public function affectingStatement(string $sql, array $bindings = []): int
    {
        try {
            $statement = $this->pdo()->prepare($sql);
            $statement->execute($bindings);

            return $statement->rowCount();
        } catch (PDOException $exception) {
            throw DatabaseOperationException::fromPdoException($exception);
        }
    }

    /**
     * Run statements atomically. MyISAM tables ignore the transaction.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     *
     * @throws DatabaseOperationException
     */
    public function transaction(callable $callback): mixed
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();

        try {
            $result = $callback();
            $pdo->commit();

            return $result;
        } catch (\Throwable $exception) {
            $pdo->rollBack();

            throw $exception;
        }
    }

    /**
     * Run a statement that returns no rows.
     *
     * @param  list<mixed>  $bindings
     *
     * @throws DatabaseOperationException
     */
    public function statement(string $sql, array $bindings = []): void
    {
        try {
            $this->pdo()->prepare($sql)->execute($bindings);
        } catch (PDOException $exception) {
            throw DatabaseOperationException::fromPdoException($exception);
        }
    }

    /**
     * @throws DatabaseOperationException
     */
    private function open(): PDO
    {
        $credentials = $this->explicitCredentials ?? $this->credentials->get() ?? throw new NotSignedInException;

        try {
            $pdo = $this->connector->connect($credentials['username'], $credentials['password'], [
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // UPDATE reports matched rows, so saving an unchanged value is not "row not found".
                self::foundRowsAttribute() => true,
            ]);
        } catch (PDOException $exception) {
            throw DatabaseOperationException::fromPdoException($exception);
        }

        $pdo->exec('SET NAMES '.$pdo->quote(config()->string('dbnova.connection.charset')));

        try {
            // MySQL 8 caches information_schema sizes for a day by default; DBNova shows live values.
            $pdo->exec('SET SESSION information_schema_stats_expiry = 0');
        } catch (PDOException) {
            // Older servers and MariaDB do not have this variable and never cache.
        }

        try {
            // Stop runaway SELECTs (e.g. an unindexed sort on millions of rows).
            $pdo->exec('SET SESSION max_execution_time = '.config()->integer('dbnova.limits.query_timeout_ms'));
        } catch (PDOException) {
            // MariaDB names this max_statement_time; DBNova does not set it there.
        }

        return $pdo;
    }

    /**
     * The MySQL thread id of this connection, used to cancel its running query.
     *
     * @throws DatabaseOperationException
     */
    public function connectionId(): int
    {
        return (int) $this->scalar('SELECT CONNECTION_ID()');
    }

    /**
     * Stop the statement running on another connection of the same account.
     * Returns false when that connection no longer exists.
     *
     * @throws DatabaseOperationException
     */
    public function killQuery(int $connectionId): bool
    {
        $credentials = $this->explicitCredentials ?? $this->credentials->get() ?? throw new NotSignedInException;

        try {
            // A separate connection: the target one is busy running the query.
            $this->connector->connect($credentials['username'], $credentials['password'])->exec('KILL QUERY '.$connectionId);
        } catch (PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) === self::UNKNOWN_THREAD) {
                return false;
            }

            throw DatabaseOperationException::fromPdoException($exception);
        }

        return true;
    }

    /**
     * Stream the next result sets row by row instead of buffering them in PHP.
     */
    public function setUnbuffered(bool $unbuffered): void
    {
        $this->pdo()->setAttribute(self::mysqlAttribute('ATTR_USE_BUFFERED_QUERY'), ! $unbuffered);
    }

    /**
     * PHP 8.4 moved the MySQL PDO constants to Pdo\Mysql and deprecated the old names.
     */
    private static function foundRowsAttribute(): int
    {
        return self::mysqlAttribute('ATTR_FOUND_ROWS');
    }

    private static function mysqlAttribute(string $name): int
    {
        return defined("Pdo\\Mysql::{$name}")
            ? (int) constant("Pdo\\Mysql::{$name}")
            : (int) constant("PDO::MYSQL_{$name}");
    }
}

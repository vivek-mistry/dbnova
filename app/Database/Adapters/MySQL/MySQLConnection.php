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
    private ?PDO $pdo = null;

    public function __construct(
        private MySQLConnector $connector,
        private MySQLCredentialStore $credentials,
    ) {}

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
        $credentials = $this->credentials->get() ?? throw new NotSignedInException;

        try {
            $pdo = $this->connector->connect($credentials['username'], $credentials['password'], [
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
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

        return $pdo;
    }
}

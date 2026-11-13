<?php

namespace App\Database\Adapters\MySQL;

use App\Database\Contracts\QueryExecutorInterface;
use App\Database\DTOs\Query\QueryRequest;
use App\Database\DTOs\Query\QueryRun;
use App\Database\DTOs\Query\StatementResult;
use App\Database\Exceptions\DatabaseOperationException;
use App\Database\Exceptions\DestructiveStatementsException;
use App\Database\Exceptions\ReadOnlyModeException;
use App\Database\Sql\SqlStatement;
use App\Database\Sql\SqlStreamScanner;
use App\Database\Support\CellEncoder;
use App\Database\Support\IdentifierQuoter;
use App\Database\Support\ReadOnlyGuard;
use PDO;
use PDOException;
use PDOStatement;

class MySQLQueryExecutor implements QueryExecutorInterface
{
    /**
     * Longest text shown for a result cell before it is cut to a preview.
     */
    private const int RESULT_PREVIEW_LENGTH = 1024;

    private const int MAX_WARNINGS = 20;

    /** MySQL error: query execution was interrupted (KILL QUERY). */
    private const int INTERRUPTED = 1317;

    /** MySQL error: maximum statement execution time exceeded. */
    private const int TIMED_OUT = 3024;

    public function __construct(
        private MySQLConnection $connection,
        private IdentifierQuoter $quoter,
        private ReadOnlyGuard $readOnlyGuard,
        private CellEncoder $cellEncoder,
    ) {}

    public function run(QueryRequest $request, ?callable $onStart = null): QueryRun
    {
        $statements = SqlStreamScanner::split($request->sql);

        if ($statements === []) {
            throw new DatabaseOperationException('There is no SQL to run.');
        }

        $readOnly = $this->readOnlyGuard->isReadOnly();

        if ($readOnly) {
            foreach ($statements as $statement) {
                if (! $statement->isReadOnly()) {
                    throw new ReadOnlyModeException;
                }
            }
        }

        if (! $request->confirmedDestructive) {
            $destructive = array_values(array_filter($statements, fn (SqlStatement $statement): bool => $statement->isDestructive()));

            if ($destructive !== []) {
                throw new DestructiveStatementsException(array_map(fn (SqlStatement $statement): array => [
                    'line' => $statement->line,
                    'keyword' => $statement->keyword(),
                    'sql' => mb_strimwidth($statement->sql, 0, 200, '…'),
                ], $destructive));
            }
        }

        $pdo = $this->connection->pdo();
        $connectionId = $this->connection->connectionId();

        if ($request->database !== null && $request->database !== '') {
            $this->connection->statement('USE '.$this->quoter->quote($request->database));
        }

        if ($readOnly) {
            $this->connection->statement('SET SESSION TRANSACTION READ ONLY');
        }

        if ($onStart !== null) {
            $onStart($connectionId);
        }

        // Send the user's SQL as typed (text protocol): some statements cannot
        // be server-side prepared, and the user's SQL has no bound parameters.
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);

        try {
            $results = [];
            $failed = false;

            foreach ($statements as $statement) {
                if ($failed) {
                    $results[] = new StatementResult($statement->line, $statement->sql, $statement->keyword(), 'skipped');

                    continue;
                }

                $result = $this->runStatement($pdo, $statement, max(1, $request->rowCap), $connectionId);
                $results[] = $result;
                $failed = $result->status === 'error';
            }
        } finally {
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

            if ($readOnly) {
                $this->connection->statement('SET SESSION TRANSACTION READ WRITE');
            }
        }

        return new QueryRun($results);
    }

    public function cancel(int $connectionId): bool
    {
        return $this->connection->killQuery($connectionId);
    }

    private function runStatement(PDO $pdo, SqlStatement $statement, int $rowCap, int $connectionId): StatementResult
    {
        $startedAt = hrtime(true);
        $this->connection->setUnbuffered(true);
        $pdoStatement = null;

        try {
            $pdoStatement = $pdo->query($statement->sql);

            if ($pdoStatement === false) {
                throw new PDOException('MySQL did not accept the statement.');
            }

            $columns = null;
            $rows = null;
            $truncated = false;
            $affectedRows = null;

            if ($pdoStatement->columnCount() > 0) {
                $columns = $this->columnNames($pdoStatement);
                $rows = [];

                while (count($rows) <= $rowCap && ($row = $pdoStatement->fetch(PDO::FETCH_NUM)) !== false) {
                    $rows[] = array_map(fn (mixed $value): mixed => $this->cellEncoder->encode($value, self::RESULT_PREVIEW_LENGTH), $row);
                }

                if (count($rows) > $rowCap) {
                    array_pop($rows);
                    $truncated = true;
                    $this->discardRemainingRows($pdo, $pdoStatement, $connectionId);
                }
            } else {
                $affectedRows = $pdoStatement->rowCount();
            }

            $pdoStatement->closeCursor();
            $this->connection->setUnbuffered(false);

            return new StatementResult(
                line: $statement->line,
                sql: $statement->sql,
                keyword: $statement->keyword(),
                status: 'ok',
                columns: $columns,
                rows: $rows,
                rowsTruncated: $truncated,
                affectedRows: $affectedRows,
                lastInsertId: $this->lastInsertId($pdo, $statement),
                warnings: $truncated ? [] : $this->warnings($pdo),
                durationMs: (hrtime(true) - $startedAt) / 1_000_000,
            );
        } catch (PDOException $exception) {
            $this->closeQuietly($pdoStatement);
            $this->connection->setUnbuffered(false);
            $code = (int) ($exception->errorInfo[1] ?? $exception->getCode());

            return new StatementResult(
                line: $statement->line,
                sql: $statement->sql,
                keyword: $statement->keyword(),
                status: 'error',
                error: match ($code) {
                    self::INTERRUPTED => 'The query was cancelled.',
                    self::TIMED_OUT => 'The query ran longer than '.(config()->integer('dbnova.limits.query_timeout_ms') / 1000).' seconds and MySQL stopped it.',
                    default => (string) ($exception->errorInfo[2] ?? $exception->getMessage()),
                },
                errorCode: $code,
                durationMs: (hrtime(true) - $startedAt) / 1_000_000,
            );
        }
    }

    /**
     * Stop MySQL sending rows past the cap: streaming the rest of a
     * 10-million-row result just to throw it away would take minutes.
     */
    private function discardRemainingRows(PDO $pdo, PDOStatement $pdoStatement, int $connectionId): void
    {
        $this->connection->killQuery($connectionId);
        $this->closeQuietly($pdoStatement);

        try {
            // If the query had already finished, the kill would hit the next statement instead; absorb it here.
            $pdo->query('DO 0');
        } catch (PDOException) {
            // The interruption was absorbed.
        }
    }

    private function closeQuietly(PDOStatement|false|null $pdoStatement): void
    {
        if (! $pdoStatement instanceof PDOStatement) {
            return;
        }

        try {
            $pdoStatement->closeCursor();
        } catch (PDOException) {
            // The statement was interrupted; nothing left to read.
        }
    }

    /**
     * @return list<string>
     */
    private function columnNames(PDOStatement $pdoStatement): array
    {
        $names = [];

        for ($index = 0; $index < $pdoStatement->columnCount(); $index++) {
            $meta = $pdoStatement->getColumnMeta($index);
            $names[] = $meta === false ? 'column '.($index + 1) : $meta['name'];
        }

        return $names;
    }

    private function lastInsertId(PDO $pdo, SqlStatement $statement): ?string
    {
        if (! in_array($statement->keyword(), ['INSERT', 'REPLACE'], true)) {
            return null;
        }

        $id = $pdo->lastInsertId();

        return $id === false || $id === '0' ? null : $id;
    }

    /**
     * @return list<array{level: string, code: int, message: string}>
     */
    private function warnings(PDO $pdo): array
    {
        try {
            $statement = $pdo->query('SHOW WARNINGS LIMIT '.self::MAX_WARNINGS);
            $rows = $statement === false ? [] : $statement->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException) {
            return [];
        }

        return array_values(array_map(fn (array $row): array => [
            'level' => (string) ($row['Level'] ?? ''),
            'code' => (int) ($row['Code'] ?? 0),
            'message' => (string) ($row['Message'] ?? ''),
        ], $rows));
    }
}

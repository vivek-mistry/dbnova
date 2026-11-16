<?php

namespace App\Database\Contracts;

use App\Database\DTOs\Query\QueryRequest;
use App\Database\DTOs\Query\QueryRun;
use App\Database\Exceptions\DatabaseOperationException;
use App\Database\Exceptions\DestructiveStatementsException;
use App\Database\Exceptions\ReadOnlyModeException;

/**
 * Runs SQL typed by the user, one statement at a time, stopping at the
 * first error. Statements are not wrapped in a transaction.
 */
interface QueryExecutorInterface
{
    /**
     * @param  (callable(int): void)|null  $onStart  Receives the connection id before anything runs, so the run can be cancelled.
     *
     * @throws DestructiveStatementsException when destructive statements are not confirmed (nothing runs).
     * @throws ReadOnlyModeException when a statement could change data in read-only mode (nothing runs).
     * @throws DatabaseOperationException when there is no SQL or the server cannot be reached.
     */
    public function run(QueryRequest $request, ?callable $onStart = null): QueryRun;

    /**
     * Stop the statement running on the given connection (the signed-in account's own).
     *
     * @throws DatabaseOperationException
     */
    public function cancel(int $connectionId): bool;
}

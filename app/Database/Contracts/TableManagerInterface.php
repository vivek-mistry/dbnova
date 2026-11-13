<?php

namespace App\Database\Contracts;

use App\Database\DTOs\Schema\DdlPlan;
use App\Database\DTOs\Schema\DdlResult;
use App\Database\DTOs\Schema\ForeignKeyInfo;
use App\Database\DTOs\Schema\SchemaChange;
use App\Database\DTOs\TableRef;
use App\Database\Exceptions\DatabaseOperationException;
use App\Database\Exceptions\LockingDdlRequiresConfirmationException;

/**
 * Schema changes (DDL). Every change can be previewed as SQL first.
 */
interface TableManagerInterface
{
    /**
     * The exact SQL a change will run, plus warnings. Runs nothing.
     *
     * @throws DatabaseOperationException when the change is invalid.
     */
    public function preview(TableRef $table, SchemaChange $change): DdlPlan;

    /**
     * Apply a change. ALTER TABLE runs online when MySQL allows it.
     *
     * @param  bool  $allowLocking  Whether a big table may be copied (blocking writes) when it cannot be changed online.
     *
     * @throws LockingDdlRequiresConfirmationException
     * @throws DatabaseOperationException
     */
    public function apply(TableRef $table, SchemaChange $change, bool $allowLocking = false): DdlResult;

    /**
     * Column types the schema editor offers, e.g. "varchar".
     *
     * @return list<string>
     */
    public function columnTypes(): array;

    /**
     * Storage engines the schema editor offers.
     *
     * @return list<string>
     */
    public function engines(): array;

    /**
     * @return list<ForeignKeyInfo>
     *
     * @throws DatabaseOperationException
     */
    public function foreignKeys(TableRef $table): array;
}

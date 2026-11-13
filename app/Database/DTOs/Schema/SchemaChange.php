<?php

namespace App\Database\DTOs\Schema;

/**
 * One change to a table's schema, previewed as SQL before it runs.
 */
interface SchemaChange
{
    /**
     * The audit log action, e.g. "schema.add_column".
     */
    public function action(): string;

    /**
     * Whether the change destroys data, so it needs the typed name and password.
     */
    public function isDestructive(): bool;

    /**
     * Whether it is an ALTER TABLE that MySQL may be able to run online.
     */
    public function isAlter(): bool;

    /**
     * Details for the audit log (names only, never data).
     *
     * @return array<string, mixed>
     */
    public function auditDetails(): array;
}

<?php

namespace App\Database\Exceptions;

/**
 * MySQL can only apply a change to a big table by copying it, which blocks
 * writes for the duration. The user must confirm before DBNova does that.
 */
class LockingDdlRequiresConfirmationException extends DatabaseOperationException
{
    public function __construct(public readonly int $approximateRows)
    {
        parent::__construct(
            'MySQL cannot make this change online. It would copy the table (about '.number_format($approximateRows)
            .' rows), blocking writes until it finishes. Confirm to run it anyway.',
        );
    }
}

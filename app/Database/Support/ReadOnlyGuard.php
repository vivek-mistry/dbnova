<?php

namespace App\Database\Support;

use App\Database\Exceptions\ReadOnlyModeException;

/**
 * Called by every adapter method that changes the managed server.
 */
class ReadOnlyGuard
{
    public function isReadOnly(): bool
    {
        return config()->boolean('dbnova.read_only');
    }

    /**
     * @throws ReadOnlyModeException
     */
    public function assertWritable(): void
    {
        if ($this->isReadOnly()) {
            throw new ReadOnlyModeException;
        }
    }
}

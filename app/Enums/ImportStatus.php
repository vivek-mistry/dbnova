<?php

namespace App\Enums;

/**
 * pending → preflight → ready | rejected; ready → queued → importing →
 * completed | failed | cancelled | interrupted. See IMPORT_EXPORT_ENGINE.md.
 */
enum ImportStatus: string
{
    case Pending = 'pending';
    case Preflight = 'preflight';
    case Rejected = 'rejected';
    case Ready = 'ready';
    case Queued = 'queued';
    case Importing = 'importing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Interrupted = 'interrupted';

    /**
     * Whether a worker is (or should be) working on the import.
     */
    public function isActive(): bool
    {
        return in_array($this, [self::Pending, self::Preflight, self::Queued, self::Importing], true);
    }

    /**
     * Whether the import has stopped for good.
     */
    public function isFinished(): bool
    {
        return in_array($this, [self::Rejected, self::Completed, self::Failed, self::Cancelled, self::Interrupted], true);
    }
}

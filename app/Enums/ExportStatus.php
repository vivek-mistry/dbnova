<?php

namespace App\Enums;

/**
 * queued → exporting → completed | failed | cancelled | interrupted;
 * completed → expired once the file is deleted. See IMPORT_EXPORT_ENGINE.md.
 */
enum ExportStatus: string
{
    case Queued = 'queued';
    case Exporting = 'exporting';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Interrupted = 'interrupted';
    case Expired = 'expired';

    /**
     * Whether a worker is (or should be) working on the export.
     */
    public function isActive(): bool
    {
        return $this === self::Queued || $this === self::Exporting;
    }
}

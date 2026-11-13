<?php

namespace App\Enums;

enum ExportFormat: string
{
    case Sql = 'sql';
    case SqlGz = 'sql.gz';

    public function extension(): string
    {
        return $this->value;
    }

    public function isCompressed(): bool
    {
        return $this === self::SqlGz;
    }
}

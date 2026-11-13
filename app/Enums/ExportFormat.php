<?php

namespace App\Enums;

enum ExportFormat: string
{
    case Sql = 'sql';
    case SqlGz = 'sql.gz';
    case Csv = 'csv';
    case CsvGz = 'csv.gz';

    public function extension(): string
    {
        return $this->value;
    }

    public function isCompressed(): bool
    {
        return $this === self::SqlGz || $this === self::CsvGz;
    }

    public function isCsv(): bool
    {
        return $this === self::Csv || $this === self::CsvGz;
    }

    public function contentType(): string
    {
        return match (true) {
            $this->isCompressed() => 'application/gzip',
            $this->isCsv() => 'text/csv; charset=utf-8',
            default => 'application/sql',
        };
    }
}

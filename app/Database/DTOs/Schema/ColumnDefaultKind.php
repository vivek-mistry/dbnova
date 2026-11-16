<?php

namespace App\Database\DTOs\Schema;

enum ColumnDefaultKind: string
{
    case None = 'none';
    case Null = 'null';
    case Value = 'value';
    case CurrentTimestamp = 'current_timestamp';
}

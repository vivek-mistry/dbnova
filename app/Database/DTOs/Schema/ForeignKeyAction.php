<?php

namespace App\Database\DTOs\Schema;

enum ForeignKeyAction: string
{
    case Restrict = 'RESTRICT';
    case Cascade = 'CASCADE';
    case SetNull = 'SET NULL';
    case NoAction = 'NO ACTION';
}

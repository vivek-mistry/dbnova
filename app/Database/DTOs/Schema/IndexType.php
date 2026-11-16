<?php

namespace App\Database\DTOs\Schema;

enum IndexType: string
{
    case Primary = 'primary';
    case Unique = 'unique';
    case Index = 'index';
    case Fulltext = 'fulltext';
}

<?php

namespace App\Services\System;

enum SystemCheckStatus: string
{
    case Ok = 'ok';
    case Warning = 'warning';
    case Failed = 'failed';
}

<?php

namespace App\Database\Exceptions;

class ReadOnlyModeException extends DatabaseOperationException
{
    public function __construct()
    {
        parent::__construct('DBNova is in read-only mode. Changes to the MySQL server are disabled.');
    }
}

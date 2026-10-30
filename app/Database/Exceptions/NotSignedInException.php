<?php

namespace App\Database\Exceptions;

class NotSignedInException extends DatabaseOperationException
{
    public function __construct()
    {
        parent::__construct('Sign in with a MySQL account to access the server.');
    }
}

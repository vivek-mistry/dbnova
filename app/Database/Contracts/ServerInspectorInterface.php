<?php

namespace App\Database\Contracts;

use App\Database\DTOs\ServerInfo;
use App\Database\Exceptions\DatabaseOperationException;

interface ServerInspectorInterface
{
    /**
     * @throws DatabaseOperationException
     */
    public function info(): ServerInfo;
}

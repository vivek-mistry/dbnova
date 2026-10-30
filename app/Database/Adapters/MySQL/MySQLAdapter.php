<?php

namespace App\Database\Adapters\MySQL;

use App\Database\Contracts\DatabaseAdapterInterface;
use App\Database\Contracts\SchemaManagerInterface;
use App\Database\Contracts\ServerInspectorInterface;

class MySQLAdapter implements DatabaseAdapterInterface
{
    public function __construct(
        private MySQLServerInspector $serverInspector,
        private MySQLSchemaManager $schemaManager,
    ) {}

    public function engine(): string
    {
        return 'mysql';
    }

    public function server(): ServerInspectorInterface
    {
        return $this->serverInspector;
    }

    public function schema(): SchemaManagerInterface
    {
        return $this->schemaManager;
    }
}

<?php

namespace App\Database\Adapters\MySQL;

use App\Database\Contracts\DatabaseAdapterInterface;
use App\Database\Contracts\ExporterInterface;
use App\Database\Contracts\ImporterInterface;
use App\Database\Contracts\QueryExecutorInterface;
use App\Database\Contracts\RecordManagerInterface;
use App\Database\Contracts\SchemaManagerInterface;
use App\Database\Contracts\ServerInspectorInterface;
use App\Database\Contracts\TableBrowserInterface;
use App\Database\Contracts\TableManagerInterface;

class MySQLAdapter implements DatabaseAdapterInterface
{
    public function __construct(
        private MySQLServerInspector $serverInspector,
        private MySQLSchemaManager $schemaManager,
        private MySQLTableBrowser $tableBrowser,
        private MySQLRecordManager $recordManager,
        private MySQLTableManager $tableManager,
        private MySQLQueryExecutor $queryExecutor,
        private MySQLImporter $importer,
        private MySQLExporter $exporter,
    ) {}

    public function importer(): ImporterInterface
    {
        return $this->importer;
    }

    public function exporter(): ExporterInterface
    {
        return $this->exporter;
    }

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

    public function browser(): TableBrowserInterface
    {
        return $this->tableBrowser;
    }

    public function records(): RecordManagerInterface
    {
        return $this->recordManager;
    }

    public function tables(): TableManagerInterface
    {
        return $this->tableManager;
    }

    public function queries(): QueryExecutorInterface
    {
        return $this->queryExecutor;
    }
}

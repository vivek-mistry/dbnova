<?php

namespace App\Database\Contracts;

/**
 * Entry point to a managed database engine. Each phase adds the accessor
 * for the capability it introduces (table browser, query executor, ...).
 */
interface DatabaseAdapterInterface
{
    /**
     * The engine name, e.g. "mysql".
     */
    public function engine(): string;

    public function server(): ServerInspectorInterface;

    public function schema(): SchemaManagerInterface;

    public function browser(): TableBrowserInterface;

    public function records(): RecordManagerInterface;

    public function tables(): TableManagerInterface;

    public function queries(): QueryExecutorInterface;

    public function importer(): ImporterInterface;

    public function exporter(): ExporterInterface;
}

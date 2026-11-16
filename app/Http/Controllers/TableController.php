<?php

namespace App\Http\Controllers;

use App\Database\Contracts\DatabaseAdapterInterface;
use App\Database\DTOs\CollationInfo;
use App\Database\DTOs\ColumnInfo;
use App\Database\DTOs\IndexInfo;
use App\Database\DTOs\PageRequest;
use App\Database\DTOs\Schema\ForeignKeyInfo;
use App\Database\DTOs\TableInfo;
use App\Database\DTOs\TableRef;
use Inertia\Inertia;
use Inertia\Response;

class TableController extends Controller
{
    public function __construct(private DatabaseAdapterInterface $adapter) {}

    /**
     * Browse a table's rows. The first page is rendered with the page; the
     * grid fetches later pages from TableRowsController.
     */
    public function show(string $database, string $table): Response
    {
        $tableRef = new TableRef($database, $table);
        $tableInfo = $this->tableOrFail($tableRef);
        $browser = $this->adapter->browser();

        return Inertia::render('tables/Browse', [
            'database' => $database,
            'table' => $tableInfo->toArray(),
            'columns' => array_map(fn (ColumnInfo $column): array => $column->toArray(), $browser->columns($tableRef)),
            'keyColumns' => $browser->keyColumns($tableRef),
            'pageSize' => config()->integer('dbnova.limits.page_size'),
            'firstPage' => $browser->page($tableRef, new PageRequest(limit: config()->integer('dbnova.limits.page_size')))->toArray(),
        ]);
    }

    /**
     * Show a table's columns, indexes and CREATE statement.
     */
    public function structure(string $database, string $table): Response
    {
        $tableRef = new TableRef($database, $table);
        $tableInfo = $this->tableOrFail($tableRef);
        $browser = $this->adapter->browser();

        return Inertia::render('tables/Structure', [
            'database' => $database,
            'table' => $tableInfo->toArray(),
            'columns' => array_map(fn (ColumnInfo $column): array => $column->toArray(), $browser->columns($tableRef)),
            'indexes' => array_map(fn (IndexInfo $index): array => $index->toArray(), $browser->indexes($tableRef)),
            'foreignKeys' => $tableInfo->type === 'table'
                ? array_map(fn (ForeignKeyInfo $foreignKey): array => $foreignKey->toArray(), $this->adapter->tables()->foreignKeys($tableRef))
                : [],
            'createStatement' => $browser->createStatement($tableRef),
            'otherTables' => array_values(array_map(
                fn (TableInfo $other): string => $other->name,
                array_filter($this->adapter->schema()->tables($database), fn (TableInfo $other): bool => $other->type === 'table'),
            )),
            'columnTypes' => $this->adapter->tables()->columnTypes(),
            'collations' => Inertia::defer(fn (): array => array_map(
                fn (CollationInfo $collation): array => $collation->toArray(),
                $this->adapter->schema()->collations(),
            )),
        ]);
    }

    private function tableOrFail(TableRef $tableRef): TableInfo
    {
        return $this->adapter->schema()->table($tableRef->database, $tableRef->table) ?? abort(404);
    }
}

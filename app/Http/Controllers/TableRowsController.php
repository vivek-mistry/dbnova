<?php

namespace App\Http\Controllers;

use App\Database\Contracts\DatabaseAdapterInterface;
use App\Database\DTOs\ColumnInfo;
use App\Database\DTOs\TableRef;
use App\Http\Requests\Tables\TableRowsRequest;
use App\Http\Requests\Tables\TableValueRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * JSON endpoints the data grid calls without a page visit.
 */
class TableRowsController extends Controller
{
    public function __construct(private DatabaseAdapterInterface $adapter) {}

    /**
     * One page of rows.
     */
    public function index(TableRowsRequest $request, string $database, string $table): JsonResponse
    {
        $tableRef = new TableRef($database, $table);
        $this->assertColumnsExist($tableRef, $request->referencedColumns());

        return response()->json($this->adapter->browser()->page($tableRef, $request->toPageRequest())->toArray());
    }

    /**
     * The exact number of rows matching the filters.
     */
    public function count(TableRowsRequest $request, string $database, string $table): JsonResponse
    {
        $tableRef = new TableRef($database, $table);
        $this->assertColumnsExist($tableRef, $request->referencedColumns());

        return response()->json(['count' => $this->adapter->browser()->count($tableRef, $request->filters())]);
    }

    /**
     * The full value of one cell.
     */
    public function value(TableValueRequest $request, string $database, string $table): JsonResponse
    {
        $tableRef = new TableRef($database, $table);
        $column = $request->string('column')->toString();
        $this->assertColumnsExist($tableRef, [$column]);

        $value = $this->adapter->browser()->value($tableRef, $column, $request->keyValues());

        return $value === null ? response()->json(['message' => 'Value not found.'], 404) : response()->json($value);
    }

    /**
     * @param  list<string>  $columnNames
     *
     * @throws ValidationException
     */
    private function assertColumnsExist(TableRef $tableRef, array $columnNames): void
    {
        $columns = $this->adapter->browser()->columns($tableRef);

        if ($columns === []) {
            abort(404);
        }

        $known = array_map(fn (ColumnInfo $column): string => $column->name, $columns);
        $unknown = array_values(array_diff($columnNames, $known));

        if ($unknown !== []) {
            throw ValidationException::withMessages(['columns' => "Unknown column \"{$unknown[0]}\"."]);
        }
    }
}

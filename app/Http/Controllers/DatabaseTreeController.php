<?php

namespace App\Http\Controllers;

use App\Database\Contracts\DatabaseAdapterInterface;
use App\Database\DTOs\DatabaseInfo;
use App\Database\DTOs\TableInfo;
use Illuminate\Http\JsonResponse;

/**
 * JSON endpoints for the sidebar database tree, which loads lazily.
 */
class DatabaseTreeController extends Controller
{
    public function __construct(private DatabaseAdapterInterface $adapter) {}

    /**
     * List database names for the tree.
     */
    public function index(): JsonResponse
    {
        return response()->json(array_map(fn (DatabaseInfo $database): array => [
            'name' => $database->name,
            'isSystem' => $database->isSystem,
        ], $this->adapter->schema()->databases()));
    }

    /**
     * List the tables and views of one database for the tree.
     */
    public function tables(string $database): JsonResponse
    {
        return response()->json(array_map(fn (TableInfo $table): array => [
            'name' => $table->name,
            'type' => $table->type,
        ], $this->adapter->schema()->tables($database)));
    }
}

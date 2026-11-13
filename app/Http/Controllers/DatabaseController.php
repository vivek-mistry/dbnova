<?php

namespace App\Http\Controllers;

use App\Database\Contracts\DatabaseAdapterInterface;
use App\Database\DTOs\CollationInfo;
use App\Database\DTOs\DatabaseInfo;
use App\Database\DTOs\TableInfo;
use App\Database\Exceptions\DatabaseOperationException;
use App\Http\Requests\Databases\DropDatabaseRequest;
use App\Http\Requests\Databases\RenameDatabaseRequest;
use App\Http\Requests\Databases\StoreDatabaseRequest;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class DatabaseController extends Controller
{
    public function __construct(
        private DatabaseAdapterInterface $adapter,
        private AuditLogger $auditLogger,
    ) {}

    /**
     * List the databases on the server.
     */
    public function index(): Response
    {
        $schema = $this->adapter->schema();

        return Inertia::render('databases/Index', [
            'databases' => array_map(fn (DatabaseInfo $database): array => $database->toArray(), $schema->databases()),
            'collations' => Inertia::defer(fn (): array => array_map(
                fn (CollationInfo $collation): array => $collation->toArray(),
                $schema->collations(),
            )),
        ]);
    }

    /**
     * Create a database.
     */
    public function store(StoreDatabaseRequest $request): RedirectResponse
    {
        $name = $request->string('name')->toString();
        $collation = $request->filled('collation') ? $request->string('collation')->toString() : null;

        try {
            $this->auditLogger->track(
                'database.create',
                $name,
                fn () => $this->adapter->schema()->createDatabase($name, $collation),
                ['collation' => $collation],
            );
        } catch (DatabaseOperationException $exception) {
            return back()->withErrors(['name' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Database ":name" created.', ['name' => $name])]);

        return to_route('databases.show', $name);
    }

    /**
     * Show a database and its tables.
     */
    public function show(string $database): Response
    {
        $schema = $this->adapter->schema();
        $databaseInfo = $schema->database($database) ?? abort(404);

        return Inertia::render('databases/Show', [
            'database' => $databaseInfo->toArray(),
            'tables' => array_map(fn (TableInfo $table): array => $table->toArray(), $schema->tables($database)),
            'renameBlockers' => $databaseInfo->isSystem ? [] : $schema->renameBlockers($database),
            'columnTypes' => $this->adapter->tables()->columnTypes(),
            'engines' => $this->adapter->tables()->engines(),
            'collations' => Inertia::defer(fn (): array => array_map(
                fn (CollationInfo $collation): array => $collation->toArray(),
                $schema->collations(),
            )),
        ]);
    }

    /**
     * Rename a database.
     */
    public function update(RenameDatabaseRequest $request, string $database): RedirectResponse
    {
        $newName = $request->string('name')->toString();

        try {
            $this->auditLogger->track(
                'database.rename',
                $database,
                fn () => $this->adapter->schema()->renameDatabase($database, $newName),
                ['new_name' => $newName],
            );
        } catch (DatabaseOperationException $exception) {
            return back()->withErrors(['name' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Database renamed to ":name".', ['name' => $newName])]);

        return to_route('databases.show', $newName);
    }

    /**
     * Drop a database.
     */
    public function destroy(DropDatabaseRequest $request, string $database): RedirectResponse
    {
        try {
            $this->auditLogger->track(
                'database.drop',
                $database,
                fn () => $this->adapter->schema()->dropDatabase($database),
            );
        } catch (DatabaseOperationException $exception) {
            return back()->withErrors(['confirmation' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Database ":name" dropped.', ['name' => $database])]);

        return to_route('databases.index');
    }
}

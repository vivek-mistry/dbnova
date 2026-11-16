<?php

namespace App\Http\Controllers;

use App\Database\Contracts\DatabaseAdapterInterface;
use App\Database\Exceptions\DatabaseOperationException;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only views of the server: global variables and status counters,
 * and the counters behind the dashboard charts.
 */
class ServerController extends Controller
{
    public function __construct(private DatabaseAdapterInterface $adapter) {}

    public function variables(): Response
    {
        return Inertia::render('processes/Variables', [
            'kind' => 'variables',
            'rows' => Inertia::defer(fn (): array => $this->rows(fn (): array => $this->adapter->server()->variables())),
        ]);
    }

    public function status(): Response
    {
        return Inertia::render('processes/Variables', [
            'kind' => 'status',
            'rows' => Inertia::defer(fn (): array => $this->rows(fn (): array => $this->adapter->server()->status())),
        ]);
    }

    /**
     * Polled by the dashboard charts.
     */
    public function metrics(): JsonResponse
    {
        try {
            return response()->json($this->adapter->server()->metrics()->toArray());
        } catch (DatabaseOperationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        }
    }

    /**
     * @param  callable(): list<array{name: string, value: string}>  $load
     * @return array{items: list<array{name: string, value: string}>, error: string|null}
     */
    private function rows(callable $load): array
    {
        try {
            return ['items' => $load(), 'error' => null];
        } catch (DatabaseOperationException $exception) {
            return ['items' => [], 'error' => $exception->getMessage()];
        }
    }
}

<?php

namespace App\Http\Controllers;

use App\Database\Contracts\DatabaseAdapterInterface;
use App\Database\Exceptions\DatabaseOperationException;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Show the server dashboard. Server statistics load after the page renders.
     */
    public function __invoke(DatabaseAdapterInterface $adapter): Response
    {
        return Inertia::render('Dashboard', [
            'server' => Inertia::defer(function () use ($adapter): array {
                try {
                    return ['info' => $adapter->server()->info()->toArray(), 'error' => null];
                } catch (DatabaseOperationException $exception) {
                    return ['info' => null, 'error' => $exception->getMessage()];
                }
            }),
        ]);
    }
}

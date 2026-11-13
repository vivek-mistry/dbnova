<?php

use App\Database\Exceptions\DatabaseOperationException;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Inertia\Inertia;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // Row values and filters are data: keep '' distinct from NULL and keep surrounding spaces.
        // These run before routing, so match the path rather than the route name.
        $middleware->trimStrings(except: [fn (Request $request) => $request->is('databases/*/tables/*', 'databases/*/schema/*', 'sql/*')]);
        $middleware->convertEmptyStringsToNull(except: [fn (Request $request) => $request->is('databases/*/tables/*', 'databases/*/schema/*', 'sql/*')]);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (DatabaseOperationException $exception, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $exception->getMessage()], 503);
            }

            return Inertia::render('DatabaseError', ['message' => $exception->getMessage()])
                ->toResponse($request)
                ->setStatusCode(503);
        });
    })->create();

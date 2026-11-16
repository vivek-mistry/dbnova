<?php

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Str;

/**
 * Every state-changing DBNova route is rate limited, and each limit has its own
 * counter: Laravel's plain `throttle:N,1` keys on the user alone, so without a
 * prefix every throttled route would share one budget (and ordinary edits would
 * use up the 5/min allowance for dropping a database).
 */
test('every state-changing route has its own rate limit', function () {
    $missing = [];
    $prefixes = [];

    foreach (Router::getRoutes()->getRoutes() as $route) {
        /** @var Route $route */
        if (! str_starts_with($route->getActionName(), 'App\\') || $route->methods() === ['GET', 'HEAD']) {
            continue;
        }

        $throttle = collect($route->gatherMiddleware())->first(fn ($middleware) => is_string($middleware) && str_starts_with($middleware, 'throttle:'));

        if ($throttle === null) {
            $missing[] = $route->uri();

            continue;
        }

        $parts = explode(',', substr($throttle, strlen('throttle:')));

        // A named limiter (e.g. "uploads") keys itself; "N,M" needs a third, unique prefix.
        if (count($parts) === 2) {
            $missing[] = $route->uri().' (no prefix)';
        } elseif (count($parts) === 3) {
            $prefixes[] = $parts[2];
        }
    }

    // Creating and renaming a database share one budget on purpose; every other prefix is unique.
    $shared = array_values(array_filter($prefixes, fn (string $prefix): bool => $prefix !== 'databases.write'));

    expect($missing)->toBe([])
        ->and($shared)->toBe(array_values(array_unique($shared)));
});

test('using up one route\'s limit does not block another', function () {
    actingAsMySqlUser();

    foreach (range(1, 30) as $attempt) {
        $this->patch(route('preferences.update'), ['page_size' => 100])->assertRedirect();
    }

    $this->patch(route('preferences.update'), ['page_size' => 100])->assertTooManyRequests();
    expect($this->postJson(route('sql.cancel'), ['runId' => (string) Str::uuid()])->status())->not->toBe(429);
});

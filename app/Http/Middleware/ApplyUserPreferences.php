<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Settings\UserPreferences;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the signed-in user's page size, row cap and query timeout for this request.
 * Queued jobs do not pass through here and keep the .env values.
 */
class ApplyUserPreferences
{
    public function __construct(private UserPreferences $preferences) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            $this->preferences->apply($user);
        }

        return $next($request);
    }
}

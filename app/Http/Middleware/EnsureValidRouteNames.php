<?php

namespace App\Http\Middleware;

use App\Database\Support\IdentifierQuoter;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A database or table name in the URL that MySQL could never have (emoji, a trailing
 * space, NUL, over 64 characters, invalid UTF-8) is a plain 404, before any lookup.
 */
class EnsureValidRouteNames
{
    public const array PARAMETERS = ['database', 'table'];

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        foreach (self::PARAMETERS as $parameter) {
            $value = $request->route($parameter);

            if (is_string($value) && ! IdentifierQuoter::isValid($value)) {
                abort(404);
            }
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auth\MySQLCredentialStore;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs the user out when their session no longer holds the MySQL
 * credentials they logged in with, since DBNova cannot work without them.
 */
class EnsureMySQLCredentials
{
    public function __construct(private MySQLCredentialStore $credentials) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        $signedInUsername = $this->credentials->get()['username'] ?? null;

        if ($user instanceof User && $signedInUsername !== $user->username) {
            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('status', __('Your MySQL session has ended. Please log in again.'));
        }

        return $next($request);
    }
}

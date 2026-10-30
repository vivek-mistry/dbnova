<?php

namespace App\Providers;

use App\Actions\Fortify\AuthenticateMySQLUser;
use App\Database\Adapters\MySQL\MySQLConnector;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions. Users sign in with their MySQL account.
     */
    private function configureActions(): void
    {
        Fortify::authenticateUsing(fn (Request $request): ?User => app(AuthenticateMySQLUser::class)($request));

        Fortify::confirmPasswordsUsing(
            fn (User $user, ?string $password): bool => app(AuthenticateMySQLUser::class)->verify($user->username, (string) $password),
        );
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/Login', [
            'server' => app(MySQLConnector::class)->serverLabel(),
            'status' => $request->session()->get('status'),
        ]));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/ConfirmPassword'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate($request->input(Fortify::username()).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });
    }
}

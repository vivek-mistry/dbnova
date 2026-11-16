<?php

namespace App\Actions\Fortify;

use App\Database\Adapters\MySQL\MySQLConnector;
use App\Models\User;
use App\Services\Auth\MySQLCredentialStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;
use PDOException;

/**
 * Signs users in with their MySQL account: the credentials are valid when
 * the MySQL server accepts a connection with them.
 */
class AuthenticateMySQLUser
{
    /**
     * Failed password checks allowed per MySQL username per minute, across login,
     * password confirmation and every "re-enter your password" dialog.
     */
    public const int MAX_FAILURES_PER_MINUTE = 10;

    public function __construct(
        private MySQLConnector $connector,
        private MySQLCredentialStore $credentials,
    ) {}

    public function __invoke(Request $request): ?User
    {
        $username = $request->string(Fortify::username())->toString();
        $password = $request->string('password')->toString();

        // The MySQL CLI tools get credentials through an option file, which a line break would break out of (SECURITY.md).
        if (strpbrk($username.$password, "\r\n\0") !== false) {
            throw ValidationException::withMessages([
                Fortify::username() => __('MySQL usernames and passwords containing line breaks are not supported. Change the password to sign in to DBNova.'),
            ]);
        }

        if (! $this->verify($username, $password)) {
            return null;
        }

        $user = User::query()->firstOrCreate(['username' => $username], ['name' => $username]);

        $this->credentials->store($username, $password);

        return $user;
    }

    /**
     * Determine whether the MySQL server accepts the given credentials for exactly this
     * account. A login that MySQL matches to a different account (an anonymous ''@host
     * user, or a proxy) is refused: DBNova keys users, exports and history on the name.
     *
     * @throws ValidationException when the server cannot be reached, or after too many failures.
     */
    public function verify(string $username, #[\SensitiveParameter] string $password): bool
    {
        // Every path that re-checks a password shares this limit, so a stolen session cannot guess it.
        $key = 'mysql-password:'.$username;

        if (RateLimiter::tooManyAttempts($key, self::MAX_FAILURES_PER_MINUTE)) {
            $message = __('Too many failed password attempts. Try again in :seconds seconds.', ['seconds' => RateLimiter::availableIn($key)]);

            // Shown on the login form (username) and in the "re-enter your password" dialogs (password).
            throw ValidationException::withMessages([Fortify::username() => $message, 'password' => $message]);
        }

        try {
            $pdo = $this->connector->connect($username, $password);
            $accepted = $this->connector->matchedUser($pdo) === $username;
        } catch (PDOException $exception) {
            if (! MySQLConnector::isAccessDenied($exception)) {
                throw ValidationException::withMessages([
                    Fortify::username() => __('Unable to reach the MySQL server at :server: :message', [
                        'server' => $this->connector->serverLabel(),
                        'message' => $exception->getMessage(),
                    ]),
                ]);
            }

            $accepted = false;
        }

        if (! $accepted) {
            RateLimiter::hit($key, 60);
        }

        return $accepted;
    }
}

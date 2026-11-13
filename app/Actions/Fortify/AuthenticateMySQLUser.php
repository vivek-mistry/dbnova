<?php

namespace App\Actions\Fortify;

use App\Database\Adapters\MySQL\MySQLConnector;
use App\Models\User;
use App\Services\Auth\MySQLCredentialStore;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;
use PDOException;

/**
 * Signs users in with their MySQL account: the credentials are valid when
 * the MySQL server accepts a connection with them.
 */
class AuthenticateMySQLUser
{
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
     * Determine whether the MySQL server accepts the given credentials.
     *
     * @throws ValidationException when the server cannot be reached.
     */
    public function verify(string $username, string $password): bool
    {
        try {
            $this->connector->connect($username, $password);
        } catch (PDOException $exception) {
            if (MySQLConnector::isAccessDenied($exception)) {
                return false;
            }

            throw ValidationException::withMessages([
                Fortify::username() => __('Unable to reach the MySQL server at :server: :message', [
                    'server' => $this->connector->serverLabel(),
                    'message' => $exception->getMessage(),
                ]),
            ]);
        }

        return true;
    }
}

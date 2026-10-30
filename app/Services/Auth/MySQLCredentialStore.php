<?php

namespace App\Services\Auth;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Crypt;

/**
 * Keeps the signed-in user's MySQL credentials in their session, encrypted
 * with the application key. They are discarded when the session ends.
 */
class MySQLCredentialStore
{
    private const string SESSION_KEY = 'dbnova.mysql_credentials';

    public function __construct(private Session $session) {}

    public function store(string $username, string $password): void
    {
        $this->session->put(self::SESSION_KEY, Crypt::encryptString(json_encode([
            'username' => $username,
            'password' => $password,
        ], JSON_THROW_ON_ERROR)));
    }

    /**
     * @return array{username: string, password: string}|null
     */
    public function get(): ?array
    {
        $encrypted = $this->session->get(self::SESSION_KEY);

        if (! is_string($encrypted)) {
            return null;
        }

        try {
            $credentials = json_decode(Crypt::decryptString($encrypted), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException) {
            return null;
        }

        if (! is_array($credentials) || ! is_string($credentials['username'] ?? null) || ! is_string($credentials['password'] ?? null)) {
            return null;
        }

        return ['username' => $credentials['username'], 'password' => $credentials['password']];
    }

    public function forget(): void
    {
        $this->session->forget(self::SESSION_KEY);
    }
}

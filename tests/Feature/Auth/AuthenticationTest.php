<?php

use App\Database\Adapters\MySQL\MySQLConnector;
use App\Models\User;
use App\Services\Auth\MySQLCredentialStore;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config([
        'dbnova.connection.host' => '127.0.0.1',
        'dbnova.connection.port' => 3306,
        'dbnova.connection.socket' => null,
    ]);
});

function mySqlAcceptsLogin(string $username, string $password): void
{
    test()->mock(MySQLConnector::class, fn ($mock) => $mock
        ->shouldReceive('connect')
        ->once()
        ->with($username, $password)
        ->andReturn(new PDO('sqlite::memory:')));
}

function mySqlRejectsLogin(PDOException $exception): void
{
    test()->mock(MySQLConnector::class, fn ($mock) => $mock
        ->shouldReceive('connect')
        ->once()
        ->andThrow($exception)
        ->shouldReceive('serverLabel')
        ->andReturn('127.0.0.1:3306'));
}

test('the login screen shows which MySQL server it connects to', function () {
    $response = $this->get(route('login'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('auth/Login')
        ->where('server', '127.0.0.1:3306')
    );
});

test('users log in with a MySQL account and keep its credentials in their session', function () {
    mySqlAcceptsLogin('root', 'Secret@123');

    $response = $this->post(route('login.store'), [
        'username' => 'root',
        'password' => 'Secret@123',
    ]);

    $response->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs(User::query()->where('username', 'root')->sole());
    expect(app(MySQLCredentialStore::class)->get())->toBe(['username' => 'root', 'password' => 'Secret@123']);
});

test('a returning MySQL user signs in to their existing DBNova account', function () {
    $user = User::factory()->create(['username' => 'root']);
    mySqlAcceptsLogin('root', 'Secret@123');

    $this->post(route('login.store'), [
        'username' => 'root',
        'password' => 'Secret@123',
    ]);

    $this->assertAuthenticatedAs($user);
    expect(User::query()->count())->toBe(1);
});

test('usernames keep their case because MySQL usernames are case-sensitive', function () {
    mySqlAcceptsLogin('Reporting', 'Secret@123');

    $this->post(route('login.store'), [
        'username' => 'Reporting',
        'password' => 'Secret@123',
    ]);

    expect(User::query()->sole()->username)->toBe('Reporting');
});

test('users cannot log in when MySQL rejects their credentials', function () {
    mySqlRejectsLogin(new PDOException("SQLSTATE[HY000] [1045] Access denied for user 'root'@'localhost'", 1045));

    $response = $this->post(route('login.store'), [
        'username' => 'root',
        'password' => 'wrong-password',
    ]);

    $response->assertSessionHasErrors(['username' => 'These credentials do not match our records.']);
    $this->assertGuest();
    expect(app(MySQLCredentialStore::class)->get())->toBeNull();
});

test('an unreachable MySQL server is reported on the login form', function () {
    mySqlRejectsLogin(new PDOException('SQLSTATE[HY000] [2002] Connection refused', 2002));

    $response = $this->post(route('login.store'), [
        'username' => 'root',
        'password' => 'Secret@123',
    ]);

    $response->assertSessionHasErrors([
        'username' => 'Unable to reach the MySQL server at 127.0.0.1:3306: SQLSTATE[HY000] [2002] Connection refused',
    ]);
    $this->assertGuest();
});

test('users are logged out when their session no longer holds MySQL credentials', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertRedirect(route('login'));
    $response->assertSessionHas('status', 'Your MySQL session has ended. Please log in again.');
    $this->assertGuest();
});

test('users are logged out when their stored credentials belong to another MySQL account', function () {
    $user = User::factory()->create(['username' => 'root']);
    actingAsMySqlUser(User::factory()->create(['username' => 'reporting']));

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertRedirect(route('login'));
    $this->assertGuest();
});

test('logging out discards the MySQL credentials', function () {
    actingAsMySqlUser();

    $response = $this->post(route('logout'));

    $response->assertRedirect(route('home'));
    $this->assertGuest();
    expect(app(MySQLCredentialStore::class)->get())->toBeNull();
});

test('users are rate limited', function () {
    RateLimiter::increment(md5('login'.implode('|', ['root', '127.0.0.1'])), amount: 5);

    $response = $this->post(route('login.store'), [
        'username' => 'root',
        'password' => 'wrong-password',
    ]);

    $response->assertTooManyRequests();
});

<?php

use App\Database\Adapters\MySQL\MySQLConnector;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('confirm password screen can be rendered', function () {
    actingAsMySqlUser();

    $response = $this->get(route('password.confirm'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('auth/ConfirmPassword'),
    );
});

test('password confirmation requires authentication', function () {
    $response = $this->get(route('password.confirm'));

    $response->assertRedirect(route('login'));
});

test('the password is confirmed when MySQL accepts it', function () {
    actingAsMySqlUser(User::factory()->create(['username' => 'root']));
    $this->mock(MySQLConnector::class, fn ($mock) => $mock
        ->shouldReceive('connect')
        ->once()
        ->with('root', 'Secret@123')
        ->andReturn(new PDO('sqlite::memory:'))
        ->shouldReceive('matchedUser')
        ->andReturn('root'));

    $response = $this->post(route('password.confirm.store'), ['password' => 'Secret@123']);

    $response->assertSessionHasNoErrors();
    $response->assertSessionHas('auth.password_confirmed_at');
});

test('the password is not confirmed when MySQL rejects it', function () {
    actingAsMySqlUser();
    $this->mock(MySQLConnector::class, fn ($mock) => $mock
        ->shouldReceive('connect')
        ->once()
        ->andThrow(new PDOException('SQLSTATE[HY000] [1045] Access denied', 1045)));

    $response = $this->post(route('password.confirm.store'), ['password' => 'wrong-password']);

    $response->assertSessionHasErrors('password');
    $response->assertSessionMissing('auth.password_confirmed_at');
});

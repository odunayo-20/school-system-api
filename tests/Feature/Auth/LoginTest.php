<?php

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('a user can log in with valid credentials and receives a token', function () {
    $user = userWithRole(Role::ADMIN);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertOk()
        ->assertJsonPath('message', 'Authenticated successfully.')
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.user.email', $user->email)
        ->assertJsonPath('data.user.role', Role::ADMIN->value)
        ->assertJsonStructure(['data' => ['user', 'token', 'token_type', 'expires_at']]);

    expect($response->json('data.token'))->toBeString()->not->toBeEmpty();

    $this->assertDatabaseCount('personal_access_tokens', 1);
});

test('the login response never exposes the password or its hash', function () {
    $user = userWithRole(Role::ADMIN);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $body = $response->getContent();

    expect($body)->not->toContain('password"')
        ->and($body)->not->toContain('$2y$')
        ->and($response->json('data.user'))->not->toHaveKey('password')
        ->and($response->json('data.user'))->not->toHaveKey('remember_token');
});

test('login is case insensitive on the email address', function () {
    $user = userWithRole(Role::REGISTRAR, ['email' => 'registrar@example.test']);

    $this->postJson('/api/v1/auth/login', [
        'email' => '  REGISTRAR@Example.Test ',
        'password' => 'password',
    ])->assertOk();
});

test('login records the last login timestamp', function () {
    $user = userWithRole(Role::ADMIN);

    expect($user->last_login_at)->toBeNull();

    loginAs($user);

    expect($user->fresh()->last_login_at)->not->toBeNull();
});

test('login fails with an invalid password', function () {
    $user = userWithRole(Role::ADMIN);

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ])->assertUnauthorized()
        ->assertJsonPath('message', 'The provided credentials are incorrect.');

    $this->assertDatabaseCount('personal_access_tokens', 0);
});

test('login fails for an unknown email address without revealing that it is unknown', function () {
    $this->postJson('/api/v1/auth/login', [
        'email' => 'nobody@example.test',
        'password' => 'password',
    ])->assertUnauthorized()
        ->assertJsonPath('message', 'The provided credentials are incorrect.');
});

test('an inactive user cannot authenticate', function () {
    $user = userWithRole(Role::ADMIN, ['status' => UserStatus::INACTIVE]);

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertUnauthorized();

    $this->assertDatabaseCount('personal_access_tokens', 0);
});

test('a suspended user cannot authenticate', function () {
    $user = userWithRole(Role::ADMIN, ['status' => UserStatus::SUSPENDED]);

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertUnauthorized();

    $this->assertDatabaseCount('personal_access_tokens', 0);
});

test('a user without a role cannot authenticate', function () {
    $user = User::factory()->create(['role_id' => null]);

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertUnauthorized();
});

test('login validation errors return 422', function () {
    $this->postJson('/api/v1/auth/login', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email', 'password']);
});

test('login is rate limited to defend against brute force attempts', function () {
    $user = userWithRole(Role::ADMIN);

    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertUnauthorized();
    }

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertStatus(429);
});

test('the issued token authenticates subsequent requests', function () {
    $user = userWithRole(Role::ADMIN);
    $token = loginAs($user);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.id', $user->id);
});

test('passwords are stored hashed, never in plaintext', function () {
    $user = userWithRole(Role::ADMIN, ['password' => Hash::make('password')]);

    expect($user->getAuthPassword())->not->toBe('password')
        ->and(password_verify('password', $user->getAuthPassword()))->toBeTrue();
});

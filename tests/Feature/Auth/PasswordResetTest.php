<?php

use App\Enums\Role;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

test('a password reset link can be requested for an existing account', function () {
    Notification::fake();

    $user = userWithRole(Role::ADMIN);

    $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])
        ->assertOk()
        ->assertJsonPath('message', 'If the account exists, password reset instructions have been sent.');

    Notification::assertSentTo($user, ResetPassword::class);
});

test('the reset response is identical for an unknown address to prevent account enumeration', function () {
    Notification::fake();

    $user = userWithRole(Role::ADMIN);

    $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email]);
    $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.test']);

    $unknown->assertOk();
    expect($unknown->json())->toBe($known->json());

    // Exactly one notification exists in total, and it belongs to the known account.
    expect(Notification::sent($user, ResetPassword::class))->toHaveCount(1);
});

test('the forgot password endpoint validates its input', function () {
    $this->postJson('/api/v1/auth/forgot-password', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);

    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'not-an-email'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

test('a password can be reset with a valid token', function () {
    Notification::fake();

    $user = userWithRole(Role::ADMIN);
    $user->createToken('existing-device');

    $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'New-Password-123',
            'password_confirmation' => 'New-Password-123',
        ])->assertOk()
            ->assertJsonPath('message', 'Password has been reset.');

        return true;
    });

    $user->refresh();

    expect(password_verify('New-Password-123', $user->password))->toBeTrue()
        ->and($user->getAuthPassword())->not->toContain('New-Password-123');

    // The reset token is single use.
    expect(User::query()->whereKey($user->id)->exists())->toBeTrue();
});

test('an invalid reset token is rejected', function () {
    $user = userWithRole(Role::ADMIN);

    $this->postJson('/api/v1/auth/reset-password', [
        'token' => 'not-a-valid-token',
        'email' => $user->email,
        'password' => 'New-Password-123',
        'password_confirmation' => 'New-Password-123',
    ])->assertStatus(422)
        ->assertJsonStructure(['message', 'errors' => ['email']]);

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

test('a reset token cannot be reused', function () {
    Notification::fake();

    $user = userWithRole(Role::ADMIN);
    $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();

    $token = null;
    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token): bool {
        $token = $notification->token;

        return true;
    });

    $payload = [
        'email' => $user->email,
        'password' => 'New-Password-123',
        'password_confirmation' => 'New-Password-123',
    ];

    $this->postJson('/api/v1/auth/reset-password', ['token' => $token] + $payload)->assertOk();
    $this->postJson('/api/v1/auth/reset-password', ['token' => $token] + $payload)->assertStatus(422);
});

test('reset password validation enforces the password policy and confirmation', function () {
    $this->postJson('/api/v1/auth/reset-password', [
        'token' => 'some-token',
        'email' => 'user@example.test',
        'password' => 'short',
        'password_confirmation' => 'mismatch',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['password']);
});

test('a password reset revokes existing api tokens', function () {
    Notification::fake();

    $user = userWithRole(Role::ADMIN);
    $user->createToken('stolen-device');

    $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'New-Password-123',
            'password_confirmation' => 'New-Password-123',
        ])->assertOk();

        return true;
    });

    $this->assertDatabaseCount('personal_access_tokens', 0);
});

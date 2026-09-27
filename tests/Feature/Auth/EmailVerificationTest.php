<?php

use App\Enums\Role;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

test('an unverified user can still log in', function () {
    $user = userWithRole(Role::ADMIN);
    $user->forceFill(['email_verified_at' => null])->save();

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    // Administrators provision accounts by email, so requiring verification to log in
    // would lock out the very first Super Admin. See docs/api/authentication.md.
    $response->assertOk()
        ->assertJsonPath('data.user.email_verified', false);
});

test('a verification notification can be requested and requires authentication', function () {
    Notification::fake();

    $this->postJson('/api/v1/auth/email/verification-notification')
        ->assertUnauthorized();

    $user = unverifiedUser(Role::ADMIN);

    Laravel\Sanctum\Sanctum::actingAs($user, ['*'], 'api');

    $this->postJson('/api/v1/auth/email/verification-notification')
        ->assertOk()
        ->assertJsonPath('message', 'Verification link sent.');

    Notification::assertSentTo($user, VerifyEmail::class);
});

test('a verification notification is refused once the address is already verified', function () {
    Notification::fake();

    $user = userWithRole(Role::ADMIN);

    Laravel\Sanctum\Sanctum::actingAs($user, ['*'], 'api');

    $this->postJson('/api/v1/auth/email/verification-notification')
        ->assertStatus(422)
        ->assertJsonPath('message', 'Email address is already verified.');

    Notification::assertNothingSent();
});

test('a signed verification link marks the address as verified', function () {
    Event::fake([Verified::class]);

    $user = unverifiedUser(Role::ADMIN);

    $url = URL::temporarySignedRoute('auth.verification.verify', now()->addMinutes(60), [
        'id' => $user->id,
        'hash' => sha1($user->getEmailForVerification()),
    ]);

    Laravel\Sanctum\Sanctum::actingAs($user, ['*'], 'api');

    $this->getJson($url)
        ->assertOk()
        ->assertJsonPath('message', 'Email address verified.');

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();

    Event::assertDispatched(Verified::class);
});

test('an unsigned verification link is rejected', function () {
    $user = unverifiedUser(Role::ADMIN);

    Laravel\Sanctum\Sanctum::actingAs($user, ['*'], 'api');

    $this->getJson("/api/v1/auth/email/verify/{$user->id}/".sha1($user->getEmailForVerification()))
        ->assertForbidden();

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('an expired verification link is rejected', function () {
    $user = unverifiedUser(Role::ADMIN);

    $url = URL::temporarySignedRoute('auth.verification.verify', now()->subMinute(), [
        'id' => $user->id,
        'hash' => sha1($user->getEmailForVerification()),
    ]);

    Laravel\Sanctum\Sanctum::actingAs($user, ['*'], 'api');

    $this->getJson($url)->assertForbidden();
});

test('a verification link whose hash does not match the address is rejected', function () {
    $user = unverifiedUser(Role::ADMIN);

    $url = URL::temporarySignedRoute('auth.verification.verify', now()->addMinutes(60), [
        'id' => $user->id,
        'hash' => sha1('someone-else@example.test'),
    ]);

    Laravel\Sanctum\Sanctum::actingAs($user, ['*'], 'api');

    $this->getJson($url)
        ->assertForbidden()
        ->assertJsonPath('message', 'Invalid verification link.');

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('the verified middleware blocks unverified users on protected routes', function () {
    registerAuthorizationTestRoutes();

    $unverified = unverifiedUser(Role::ADMIN);
    Laravel\Sanctum\Sanctum::actingAs($unverified, ['*'], 'api');

    $this->getJson('/_test/verified-only')->assertStatus(403);

    $verified = userWithRole(Role::ADMIN);
    Laravel\Sanctum\Sanctum::actingAs($verified, ['*'], 'api');

    $this->getJson('/_test/verified-only')->assertOk();
});

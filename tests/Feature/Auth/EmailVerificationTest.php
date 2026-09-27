<?php

use App\Enums\Role;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;

test('an unverified user can still log in', function () {
    $user = unverifiedUser(Role::ADMIN);

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ])
        // Administrators provision accounts by email, so requiring verification to log in
        // would lock out the very first Super Admin. See docs/api/authentication.md.
        ->assertOk()
        ->assertJsonPath('data.user.email_verified', false);
});

test('a verification notification can be requested and requires authentication', function () {
    Notification::fake();

    $this->postJson('/api/v1/auth/email/verification-notification')
        ->assertUnauthorized();

    $user = unverifiedUser(Role::ADMIN);

    Sanctum::actingAs($user, ['*'], 'api');

    $this->postJson('/api/v1/auth/email/verification-notification')
        ->assertOk()
        ->assertJsonPath('message', 'Verification link sent.');

    Notification::assertSentTo($user, VerifyEmail::class);
});

test('a verification notification is refused once the address is already verified', function () {
    Notification::fake();

    $user = userWithRole(Role::ADMIN);

    Sanctum::actingAs($user, ['*'], 'api');

    $this->postJson('/api/v1/auth/email/verification-notification')
        ->assertStatus(422)
        ->assertJsonPath('message', 'Email address is already verified.');

    Notification::assertNothingSent();
});

test('the link inside a real notification resolves and works with no bearer token', function () {
    Notification::fake();

    $user = unverifiedUser(Role::ADMIN);

    Sanctum::actingAs($user, ['*'], 'api');
    $this->postJson('/api/v1/auth/email/verification-notification')->assertOk();

    // Regression guard: the notification must point at the prefixed route that exists.
    // The default Laravel URL generator asks for "verification.verify", which this
    // application does not define, so a real mail would have thrown RouteNotFound.
    $url = null;
    Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $notification) use ($user, &$url): bool {
        $url = $notification->toMail($user)->actionUrl;

        return true;
    });

    expect($url)->toContain('/api/v1/auth/email/verify/'.$user->id);
    expect($url)->toContain('signature=');

    // The link is opened in a browser, so it carries no Authorization header at all.
    forgetResolvedUser();

    $this->getJson($url)
        ->assertOk()
        ->assertJsonPath('message', 'Email address verified.');

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

test('a signed verification link marks the address as verified', function () {
    Event::fake([Verified::class]);

    $user = unverifiedUser(Role::ADMIN);

    $url = URL::temporarySignedRoute('auth.verification.verify', now()->addMinutes(60), [
        'id' => $user->id,
        'hash' => sha1($user->getEmailForVerification()),
    ]);

    $this->getJson($url)
        ->assertOk()
        ->assertJsonPath('message', 'Email address verified.');

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();

    Event::assertDispatched(Verified::class);
});

test('an already verified address reports 422 when the link is followed again', function () {
    $user = userWithRole(Role::ADMIN);

    $url = URL::temporarySignedRoute('auth.verification.verify', now()->addMinutes(60), [
        'id' => $user->id,
        'hash' => sha1($user->getEmailForVerification()),
    ]);

    $this->getJson($url)
        ->assertStatus(422)
        ->assertJsonPath('message', 'Email address is already verified.');
});

test('an unsigned verification link is rejected', function () {
    $user = unverifiedUser(Role::ADMIN);

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

    $this->getJson($url)->assertForbidden();
});

test('a verification link whose hash does not match the address is rejected', function () {
    $user = unverifiedUser(Role::ADMIN);

    $url = URL::temporarySignedRoute('auth.verification.verify', now()->addMinutes(60), [
        'id' => $user->id,
        'hash' => sha1('someone-else@example.test'),
    ]);

    $this->getJson($url)
        ->assertForbidden()
        ->assertJsonPath('message', 'Invalid verification link.');

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('a verification link for an unknown user is rejected', function () {
    $url = URL::temporarySignedRoute('auth.verification.verify', now()->addMinutes(60), [
        'id' => 999999,
        'hash' => sha1('ghost@example.test'),
    ]);

    $this->getJson($url)
        ->assertForbidden()
        ->assertJsonPath('message', 'Invalid verification link.');
});

test('a verification link with a non numeric id is rejected', function () {
    $url = URL::temporarySignedRoute('auth.verification.verify', now()->addMinutes(60), [
        'id' => 'abc',
        'hash' => sha1('ghost@example.test'),
    ]);

    $this->getJson($url)
        ->assertForbidden()
        ->assertJsonPath('message', 'Invalid verification link.');
});

test('the verified middleware blocks unverified users on protected routes', function () {
    registerAuthorizationTestRoutes();

    $unverified = unverifiedUser(Role::ADMIN);
    Sanctum::actingAs($unverified, ['*'], 'api');

    $this->getJson('/_test/verified-only')
        ->assertForbidden()
        ->assertJsonPath('message', 'Your email address is not verified. Request a new link with POST /api/v1/auth/email/verification-notification.');

    $verified = userWithRole(Role::ADMIN);
    Sanctum::actingAs($verified, ['*'], 'api');

    $this->getJson('/_test/verified-only')->assertOk();
});

test('the verified middleware answers 403 json without an accept header', function () {
    registerAuthorizationTestRoutes();

    $unverified = unverifiedUser(Role::ADMIN);
    Sanctum::actingAs($unverified, ['*'], 'api');

    // Regression guard: the framework middleware branches on expectsJson() and would
    // otherwise redirect to the non-existent "verification.notice" route, producing a
    // 500 instead of a 403.
    $response = $this->get('/_test/verified-only');

    $response->assertForbidden();
    $response->assertHeader('content-type', 'application/json');
    expect($response->json('message'))->toContain('not verified');
});

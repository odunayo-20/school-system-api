<?php

use App\Enums\Role;
use App\Enums\StaffType;
use App\Enums\UserStatus;
use App\Models\Staff;

test('the current user endpoint requires authentication', function () {
    $this->getJson('/api/v1/auth/me')
        ->assertUnauthorized()
        ->assertJsonPath('message', 'Unauthenticated.');
});

test('the current user endpoint rejects an invalid token', function () {
    $this->withHeader('Authorization', 'Bearer not-a-real-token')
        ->getJson('/api/v1/auth/me')
        ->assertUnauthorized();
});

test('the current user endpoint returns the profile and authorization context', function () {
    $user = userWithRole(Role::STAFF);
    Staff::factory()->create([
        'user_id' => $user->id,
        'staff_type' => StaffType::TEACHING,
    ]);

    $token = loginAs($user);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.name', $user->name)
        ->assertJsonPath('data.email', $user->email)
        ->assertJsonPath('data.role', Role::STAFF->value)
        ->assertJsonPath('data.staff_type', StaffType::TEACHING->value)
        ->assertJsonPath('data.status', 'ACTIVE')
        ->assertJsonPath('data.email_verified', true)
        ->assertJsonStructure(['data' => ['id', 'name', 'email', 'status', 'role', 'permissions']]);
});

test('the current user payload never exposes sensitive fields', function () {
    $user = userWithRole(Role::ADMIN);
    $token = loginAs($user);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/auth/me');

    $response->assertOk()
        ->assertJsonMissingPath('data.password')
        ->assertJsonMissingPath('data.remember_token')
        ->assertJsonMissingPath('data.role_id')
        ->assertJsonMissingPath('data.email_verified_at');

    expect($response->getContent())->not->toContain('$2y$');
});

test('a non staff user has no staff type in the payload', function () {
    $user = userWithRole(Role::ADMIN);
    $token = loginAs($user);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonMissingPath('data.staff_type');
});

test('a suspended user loses access immediately, not only at the next login', function () {
    $user = userWithRole(Role::ADMIN);
    $token = loginAs($user);

    forgetResolvedUser();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/auth/me')
        ->assertOk();

    $user->forceFill(['status' => UserStatus::SUSPENDED])->save();

    forgetResolvedUser();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/auth/me')
        ->assertForbidden()
        ->assertJsonPath('message', 'This account has been suspended.');

    $this->assertDatabaseCount('personal_access_tokens', 0);
});

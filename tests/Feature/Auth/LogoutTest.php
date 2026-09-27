<?php

use App\Enums\Role;

test('logout invalidates the token used for the request', function () {
    $user = userWithRole(Role::ADMIN);
    $token = loginAs($user);

    forgetResolvedUser();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/auth/logout')
        ->assertOk()
        ->assertJsonPath('message', 'Logged out successfully.');

    $this->assertDatabaseCount('personal_access_tokens', 0);

    forgetResolvedUser();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/auth/me')
        ->assertUnauthorized();
});

test('logging out only revokes the token used for the request', function () {
    $user = userWithRole(Role::ADMIN);
    $first = loginAs($user);
    $second = loginAs($user);

    $this->assertDatabaseCount('personal_access_tokens', 2);

    forgetResolvedUser();

    $this->withHeader('Authorization', "Bearer {$first}")
        ->postJson('/api/v1/auth/logout')
        ->assertOk();

    $this->assertDatabaseCount('personal_access_tokens', 1);

    forgetResolvedUser();

    $this->withHeader('Authorization', "Bearer {$second}")
        ->getJson('/api/v1/auth/me')
        ->assertOk();

    forgetResolvedUser();

    $this->withHeader('Authorization', "Bearer {$first}")
        ->getJson('/api/v1/auth/me')
        ->assertUnauthorized();
});

test('logout requires authentication', function () {
    $this->postJson('/api/v1/auth/logout')->assertUnauthorized();
});

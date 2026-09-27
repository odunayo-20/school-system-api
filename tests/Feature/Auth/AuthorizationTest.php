<?php

use App\Enums\Role;
use App\Models\Permission;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    registerAuthorizationTestRoutes();
});

test('an unauthenticated request is rejected with 401', function () {
    $this->getJson('/_test/admin-only')
        ->assertUnauthorized()
        ->assertJsonPath('message', 'Unauthenticated.');
});

test('a super admin passes a role restricted route', function () {
    Sanctum::actingAs(userWithRole(Role::SUPER_ADMIN), ['*'], 'api');

    $this->getJson('/_test/admin-only')->assertOk();
});

test('an admin passes a role restricted route', function () {
    Sanctum::actingAs(userWithRole(Role::ADMIN), ['*'], 'api');

    $this->getJson('/_test/admin-only')->assertOk();
});

test('a registrar is denied a role restricted admin route with 403', function () {
    Sanctum::actingAs(userWithRole(Role::REGISTRAR), ['*'], 'api');

    $this->getJson('/_test/admin-only')
        ->assertForbidden()
        ->assertJsonPath('message', 'This action is unauthorized.');
});

test('a staff user is denied a role restricted admin route', function () {
    Sanctum::actingAs(userWithRole(Role::STAFF), ['*'], 'api');

    $this->getJson('/_test/admin-only')->assertForbidden();
});

test('a student is denied a role restricted admin route', function () {
    Sanctum::actingAs(userWithRole(Role::STUDENT), ['*'], 'api');

    $this->getJson('/_test/admin-only')->assertForbidden();
});

test('every role can reach a route that only requires authentication', function () {
    foreach ([Role::SUPER_ADMIN, Role::ADMIN, Role::REGISTRAR, Role::STAFF, Role::STUDENT] as $role) {
        Sanctum::actingAs(userWithRole($role), ['*'], 'api');

        $this->getJson('/_test/any-authenticated')->assertOk();
    }
});

test('a permission is granted through the role', function () {
    Sanctum::actingAs(userWithRole(Role::ADMIN), ['*'], 'api');

    $this->postJson('/_test/permission-gated')->assertOk();
});

test('a user without the permission is denied with 403', function () {
    Sanctum::actingAs(userWithRole(Role::REGISTRAR), ['*'], 'api');

    $this->postJson('/_test/permission-gated')->assertForbidden();
});

test('a student cannot reach an administrative permission', function () {
    Sanctum::actingAs(userWithRole(Role::STUDENT), ['*'], 'api');

    $this->postJson('/_test/permission-gated')->assertForbidden();
});

test('the super admin bypass is centralised in the gate', function () {
    $superAdmin = userWithRole(Role::SUPER_ADMIN);

    expect($superAdmin->hasPermission('a.permission.that.does.not.exist'))->toBeTrue();

    Sanctum::actingAs($superAdmin, ['*'], 'api');

    // No such permission has been seeded, yet the gate allows it: the bypass lives in
    // AuthServiceProvider and applies everywhere, including policies.
    $this->postJson('/_test/permission-gated')->assertOk();
    $this->getJson('/_test/admin-only')->assertOk();
});

test('a permission seeded by a later module is enforced without any code change', function () {
    $registrar = userWithRole(Role::REGISTRAR);

    Sanctum::actingAs($registrar, ['*'], 'api');
    $this->getJson('/_test/future-permission')->assertForbidden();

    // A future module seeds its own permission and attaches it to a role.
    $permission = Permission::query()->create(['name' => 'students.view', 'label' => 'View students']);
    $registrar->role->permissions()->attach($permission);

    $registrar->unsetRelation('role');

    $this->getJson('/_test/future-permission')->assertOk();
});

test('a direct grant lets one user hold a permission their role does not', function () {
    $registrar = userWithRole(Role::REGISTRAR);

    expect($registrar->hasPermission('users.create'))->toBeFalse();

    grantPermission($registrar, 'users.create');

    expect($registrar->hasPermission('users.create'))->toBeTrue();

    Sanctum::actingAs($registrar->fresh(), ['*'], 'api');

    $this->postJson('/_test/permission-gated')->assertOk();
});

test('a role middleware accepts any of the listed roles', function () {
    Route::middleware(['auth:api', 'active', 'role:ADMIN,REGISTRAR'])
        ->get('/_test/admin-or-registrar', fn () => response()->json(['ok' => true]));

    Sanctum::actingAs(userWithRole(Role::ADMIN), ['*'], 'api');
    $this->getJson('/_test/admin-or-registrar')->assertOk();

    Sanctum::actingAs(userWithRole(Role::REGISTRAR), ['*'], 'api');
    $this->getJson('/_test/admin-or-registrar')->assertOk();

    Sanctum::actingAs(userWithRole(Role::STUDENT), ['*'], 'api');
    $this->getJson('/_test/admin-or-registrar')->assertForbidden();
});

test('an inactive account is forbidden on an otherwise permitted route', function () {
    Sanctum::actingAs(userWithRole(Role::ADMIN, ['status' => \App\Enums\UserStatus::INACTIVE]), ['*'], 'api');

    $this->getJson('/_test/admin-only')
        ->assertForbidden()
        ->assertJsonPath('message', 'This account is not active.');
});

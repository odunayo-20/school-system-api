<?php

use App\Enums\Role;
use App\Models\Permission;
use App\Models\Role as RoleModel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Often, you may
| need to change it using the "pest()->extend()" function.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
|
| Shared helpers for the authentication and authorization feature tests. Roles and
| Module 01 permissions are seeded from Tests\TestCase::setUp().
|
*/

/**
 * Log in through the real endpoint and return the plain text bearer token.
 */
function loginAs(User $user, string $password = 'password'): string
{
    $response = test()->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => $password,
    ]);

    $response->assertOk();

    return $response->json('data.token');
}

function userWithRole(Role $role, array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'role_id' => RoleModel::where('name', $role->value)->value('id'),
        'password' => Hash::make('password'),
    ], $attributes));
}

/**
 * A user whose email address has not been verified yet.
 */
function unverifiedUser(Role $role): User
{
    $user = userWithRole($role);

    $user->forceFill(['email_verified_at' => null])->save();

    return $user;
}

/**
 * Register throwaway routes so authorization middleware can be exercised without
 * creating placeholder endpoints for modules that do not exist yet.
 */
function registerAuthorizationTestRoutes(): void
{
    Route::middleware(['auth:api', 'active'])
        ->get('/_test/any-authenticated', fn () => response()->json(['ok' => true]));

    Route::middleware(['auth:api', 'active', 'role:ADMIN'])
        ->get('/_test/admin-only', fn () => response()->json(['ok' => true]));

    Route::middleware(['auth:api', 'active', 'permission:users.create'])
        ->post('/_test/permission-gated', fn () => response()->json(['ok' => true]));

    Route::middleware(['auth:api', 'active', 'permission:students.view'])
        ->get('/_test/future-permission', fn () => response()->json(['ok' => true]));

    Route::middleware(['auth:api', 'active', 'verified'])
        ->get('/_test/verified-only', fn () => response()->json(['ok' => true]));
}

function grantPermission(User $user, string $name): void
{
    $user->directPermissions()->attach(
        Permission::query()->firstOrCreate(
            ['name' => $name],
            ['label' => 'Seeded by test'],
        )
    );

    $user->unsetRelation('directPermissions')->unsetRelation('role');
}

/**
 * A test process handles several requests against one application instance, and the
 * auth manager caches the resolved user per guard. Forget the guards so the next
 * request re-resolves the user from its token, exactly as a real request would.
 */
function forgetResolvedUser(): void
{
    app('auth')->forgetGuards();
}

<?php

use App\Enums\EmploymentStatus;
use App\Enums\Role;
use App\Enums\StaffType;
use App\Enums\UserStatus;
use App\Models\Staff;

/*
|--------------------------------------------------------------------------
| Employment status transitions
|--------------------------------------------------------------------------
|
| The separation this file exists to defend: employment status is not the account status.
| Most assertions here are about what a transition must NOT touch.
|
*/

/*
|--------------------------------------------------------------------------
| The two dedicated endpoints
|--------------------------------------------------------------------------
*/

it('activates a staff member', function (): void {
    $staff = staffMember(StaffType::TEACHING, ['status' => EmploymentStatus::INACTIVE]);

    asUser(userWithRole(Role::ADMIN))
        ->postJson("/api/v1/staff/{$staff->id}/activate")
        ->assertOk()
        ->assertJsonPath('data.status', 'ACTIVE');

    expect($staff->refresh()->status)->toBe(EmploymentStatus::ACTIVE);
});

it('deactivates a staff member', function (): void {
    $staff = staffMember(StaffType::TEACHING);

    asUser(userWithRole(Role::ADMIN))
        ->postJson("/api/v1/staff/{$staff->id}/deactivate")
        ->assertOk()
        ->assertJsonPath('data.status', 'INACTIVE');

    expect($staff->refresh()->status)->toBe(EmploymentStatus::INACTIVE);
});

it('treats re-activating somebody already active as a success rather than a conflict', function (): void {
    $staff = staffMember(StaffType::TEACHING);

    asUser(userWithRole(Role::ADMIN))
        ->postJson("/api/v1/staff/{$staff->id}/activate")
        ->assertOk()
        ->assertJsonPath('data.status', 'ACTIVE');
});

it('treats de-activating somebody already inactive as a success rather than a conflict', function (): void {
    $staff = staffMember(StaffType::TEACHING, ['status' => EmploymentStatus::INACTIVE]);

    asUser(userWithRole(Role::ADMIN))
        ->postJson("/api/v1/staff/{$staff->id}/deactivate")
        ->assertOk()
        ->assertJsonPath('data.status', 'INACTIVE');
});

/*
|--------------------------------------------------------------------------
| The separation from the login account
|--------------------------------------------------------------------------
*/

it('leaves the login account, its verification and its tokens untouched when deactivating', function (): void {
    $staff = staffMember(StaffType::TEACHING);
    $user = $staff->user;

    // A live token, so "untouched" can be asserted about something real rather than an
    // empty table.
    $token = $user->createToken('test')->plainTextToken;
    $tokensBefore = $user->tokens()->count();
    $verifiedBefore = $user->email_verified_at;

    asUser(userWithRole(Role::ADMIN))
        ->postJson("/api/v1/staff/{$staff->id}/deactivate")
        ->assertOk()
        // Both statuses are reported together, so the mismatch is visible in the response
        // rather than something an administrator has to remember to go and look for.
        ->assertJsonPath('data.status', 'INACTIVE')
        ->assertJsonPath('data.account_status', 'ACTIVE');

    $user->refresh();

    expect($user->status)->toBe(UserStatus::ACTIVE)
        ->and($user->email_verified_at?->timestamp)->toBe($verifiedBefore?->timestamp)
        ->and($user->tokens()->count())->toBe($tokensBefore)
        ->and($token)->toBeString();

    // The consequence is real and is stated rather than engineered away: a staff member
    // deactivated here can still log in, because their account is a separate fact. The
    // token still authenticates.
    asUser($user)
        ->getJson('/api/v1/auth/me')
        ->assertOk();
});

it('deactivates a staff member whose account is suspended', function (): void {
    // The two statuses are independent, so a suspended account must not stop the
    // employment record changing.
    $staff = staffMember(StaffType::TEACHING);
    $staff->user->forceFill(['status' => UserStatus::SUSPENDED])->save();

    asUser(userWithRole(Role::ADMIN))
        ->postJson("/api/v1/staff/{$staff->id}/deactivate")
        ->assertOk()
        ->assertJsonPath('data.status', 'INACTIVE')
        ->assertJsonPath('data.account_status', 'SUSPENDED');

    expect($staff->refresh()->status)->toBe(EmploymentStatus::INACTIVE)
        ->and($staff->user->refresh()->status)->toBe(UserStatus::SUSPENDED);
});

/*
|--------------------------------------------------------------------------
| Termination
|--------------------------------------------------------------------------
*/

it('terminates a staff member through the amend endpoint', function (): void {
    $staff = staffMember(StaffType::TEACHING);

    asUser(userWithRole(Role::ADMIN))
        ->putJson("/api/v1/staff/{$staff->id}", staffUpdatePayload(['status' => 'TERMINATED']))
        ->assertOk()
        ->assertJsonPath('data.status', 'TERMINATED');

    expect($staff->refresh()->status)->toBe(EmploymentStatus::TERMINATED);
});

it('refuses to reactivate a terminated employment', function (): void {
    $staff = staffMember(StaffType::TEACHING, ['status' => EmploymentStatus::TERMINATED]);

    asUser(userWithRole(Role::ADMIN))
        ->postJson("/api/v1/staff/{$staff->id}/activate")
        ->assertStatus(422);

    // Employment that has ended does not un-happen. A status that could be reversed would
    // put a leaver back on a current staff list after they had gone.
    expect($staff->refresh()->status)->toBe(EmploymentStatus::TERMINATED);
});

it('refuses to make a terminated employment inactive', function (): void {
    $staff = staffMember(StaffType::TEACHING, ['status' => EmploymentStatus::TERMINATED]);

    asUser(userWithRole(Role::ADMIN))
        ->postJson("/api/v1/staff/{$staff->id}/deactivate")
        ->assertStatus(422);

    expect($staff->refresh()->status)->toBe(EmploymentStatus::TERMINATED);
});

it('refuses to amend a terminated staff record', function (): void {
    $staff = staffMember(StaffType::TEACHING, ['status' => EmploymentStatus::TERMINATED]);

    asUser(userWithRole(Role::ADMIN))
        ->putJson("/api/v1/staff/{$staff->id}", staffUpdatePayload(['designation' => 'Something Else']))
        ->assertStatus(422);

    // The record and its history are kept; the fact that it ended does not change.
    expect($staff->refresh()->designation)->not->toBe('Something Else')
        ->and(Staff::query()->whereKey($staff->id)->exists())->toBeTrue();
});

it('rejects an unknown status and names the permitted values', function (): void {
    $staff = staffMember(StaffType::TEACHING);

    asUser(userWithRole(Role::ADMIN))
        ->putJson("/api/v1/staff/{$staff->id}", staffUpdatePayload(['status' => 'ON_HOLIDAY']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('status')
        ->assertJsonFragment(['The status must be one of: ACTIVE, INACTIVE, TERMINATED.']);
});

it('does not accept a status on create', function (): void {
    // A record born already terminated would need a second call before it could ever be
    // used, and a new staff member is actively employed by definition.
    asUser(userWithRole(Role::ADMIN))
        ->postJson('/api/v1/staff', staffCreatePayload(['status' => 'TERMINATED']))
        ->assertStatus(201)
        ->assertJsonPath('data.status', 'ACTIVE');
});

/*
|--------------------------------------------------------------------------
| Permission separation between the two transitions
|--------------------------------------------------------------------------
*/

it('gates activation and deactivation behind their own permissions', function (): void {
    // Registrar holds staff.update but deliberately not staff.activate or
    // staff.deactivate: ending somebody's employment, while their account is still open,
    // is an employment decision with a security dimension.
    $registrar = userWithRole(Role::REGISTRAR);
    $staff = staffMember(StaffType::TEACHING);

    asUser($registrar)
        ->postJson("/api/v1/staff/{$staff->id}/deactivate")
        ->assertStatus(403);

    expect($staff->refresh()->status)->toBe(EmploymentStatus::ACTIVE);
});

it('refuses every staff transition to a student and to a staff member', function (): void {
    foreach ([Role::STUDENT, Role::STAFF] as $role) {
        $staff = staffMember(StaffType::TEACHING);

        asUser(userWithRole($role))
            ->postJson("/api/v1/staff/{$staff->id}/activate")
            ->assertStatus(403);

        asUser(userWithRole($role))
            ->postJson("/api/v1/staff/{$staff->id}/deactivate")
            ->assertStatus(403);

        expect($staff->refresh()->status)->toBe(EmploymentStatus::ACTIVE);
    }
});

it('requires an active account to perform a transition', function (): void {
    $staff = staffMember(StaffType::TEACHING);
    $admin = userWithRole(Role::ADMIN);

    // Authenticated while the account is still active, then suspended. A suspended
    // account cannot log in at all, so the token has to be issued first: what is under
    // test is that a token minted before the suspension stops working, which is the
    // "active" middleware rather than the login endpoint.
    $request = asUser($admin);

    $admin->forceFill(['status' => UserStatus::SUSPENDED])->save();

    $request->postJson("/api/v1/staff/{$staff->id}/deactivate")
        ->assertStatus(403);

    expect($staff->refresh()->status)->toBe(EmploymentStatus::ACTIVE);
});

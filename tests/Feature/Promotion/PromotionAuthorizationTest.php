<?php

use App\Enums\Role;
use App\Enums\StaffType;
use App\Enums\UserStatus;
use App\Models\Permission;
use App\Models\Promotion;
use App\Models\Role as RoleModel;

/*
|--------------------------------------------------------------------------
| Promotion authorization: permission matrix, IDOR and revocation
| (Module 15)
|--------------------------------------------------------------------------
*/

/*
| Base authentication
*/

it('refuses promotion endpoints to an unauthenticated caller', function (): void {
    $context = promotionContext();

    test()->postJson("/api/v1/students/{$context['enrollment']->student_id}/promote", promotePayload($context))
        ->assertUnauthorized();
    test()->getJson('/api/v1/promotions')->assertUnauthorized();
});

it('refuses promotion endpoints to a suspended account, even with a token minted before the suspension', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);
    $context = promotionContext();

    $admin->forceFill(['status' => UserStatus::SUSPENDED])->save();
    forgetResolvedUser();

    withToken($token)->postJson("/api/v1/students/{$context['enrollment']->student_id}/promote", promotePayload($context))
        ->assertForbidden();
});

/*
| Permission matrix
*/

it('grants promotion access to super admin, admin and registrar', function (): void {
    foreach ([Role::SUPER_ADMIN, Role::ADMIN, Role::REGISTRAR] as $role) {
        $token = loginAs(userWithRole($role));
        $context = promotionContext();

        withToken($token)->postJson("/api/v1/students/{$context['enrollment']->student_id}/promote", promotePayload($context))
            ->assertCreated();
        withToken($token)->getJson('/api/v1/promotions')->assertOk();
    }
});

it('refuses teaching staff outright', function (): void {
    $context = promotionContext();
    $staffMember = staffMember(StaffType::TEACHING);
    $token = loginAs($staffMember->user);

    withToken($token)->postJson("/api/v1/students/{$context['enrollment']->student_id}/promote", promotePayload($context))
        ->assertForbidden();
    withToken($token)->getJson('/api/v1/promotions')->assertForbidden();
});

it('refuses a non-teaching staff member', function (): void {
    $context = promotionContext();
    $staffMember = staffMember(StaffType::NON_TEACHING);
    $token = loginAs($staffMember->user);

    withToken($token)->postJson("/api/v1/students/{$context['enrollment']->student_id}/promote", promotePayload($context))
        ->assertForbidden();
    withToken($token)->getJson('/api/v1/promotions')->assertForbidden();
});

it('refuses a student, even for their own promotion history', function (): void {
    $student = pupilWithAccount();
    $token = loginAs($student->user);

    withToken($token)->getJson('/api/v1/promotions')->assertForbidden();
});

it('seeds exactly two permissions for this module', function (): void {
    $names = Permission::query()->where('name', 'like', 'promotions.%')->pluck('name')->all();

    expect($names)->toEqualCanonicalizing(['promotions.view', 'promotions.create']);
});

it('adds the module permissions without revoking the earlier modules', function (): void {
    $admin = RoleModel::query()->where('name', Role::ADMIN->value)->firstOrFail();
    $held = $admin->permissions()->pluck('name')->all();

    expect($held)->toContain('enrollments.create')
        ->toContain('results.view')
        ->toContain('promotions.view')
        ->toContain('promotions.create');
});

it('requires promotions.create separately from promotions.view', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);

    $admin->role->permissions()->detach(
        Permission::query()->where('name', 'promotions.create')->value('id')
    );

    $context = promotionContext();

    // Still holds promotions.view (a different permission) but not promotions.create.
    withToken($token)->getJson('/api/v1/promotions')->assertOk();
    withToken($token)->postJson("/api/v1/students/{$context['enrollment']->student_id}/promote", promotePayload($context))
        ->assertForbidden();
});

it('requires promotions.view separately from promotions.create', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);

    $admin->role->permissions()->detach(
        Permission::query()->where('name', 'promotions.view')->value('id')
    );

    $promotion = promotedStudent();

    withToken($token)->getJson('/api/v1/promotions')->assertForbidden();
    withToken($token)->getJson("/api/v1/promotions/{$promotion->id}")->assertForbidden();
});

/*
| IDOR
*/

it('refuses to promote a student using an enrollment id that belongs to a different student', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $context = promotionContext();
    $otherStudent = pupil();

    // The enrollment id is real and ACTIVE, but it does not belong to the student named
    // in the URL - a naive implementation trusting the enrollment id alone would let a
    // caller promote a student who was never named in the request.
    withToken($token)->postJson(
        "/api/v1/students/{$otherStudent->id}/promote",
        promotePayload($context)
    )->assertUnprocessable();

    expect(Promotion::query()->count())->toBe(0);
});

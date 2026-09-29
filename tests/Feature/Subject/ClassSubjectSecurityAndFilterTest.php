<?php

use App\Enums\CatalogStatus;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\ClassSubject;
use App\Models\Permission;

/*
|--------------------------------------------------------------------------
| Class subject authorization, filters and exposure (Module 07)
|--------------------------------------------------------------------------
*/

/*
| Authorization
*/

it('refuses class subjects to an unauthenticated caller', function (): void {
    activeClassSubject();

    test()->getJson('/api/v1/class-subjects')->assertUnauthorized();
    test()->postJson('/api/v1/class-subjects', classSubjectCreatePayload())->assertUnauthorized();
});

it('refuses class subjects to a user with no class_subjects permission', function (): void {
    $teacher = userWithRole(Role::STAFF);
    $token = loginAs($teacher);

    withToken($token)->getJson('/api/v1/class-subjects')->assertForbidden();
    withToken($token)->postJson('/api/v1/class-subjects', classSubjectCreatePayload())->assertForbidden();
});

it('grants class subjects to super admin, admin and registrar', function (): void {
    foreach ([Role::SUPER_ADMIN, Role::ADMIN, Role::REGISTRAR] as $role) {
        $token = loginAs(userWithRole($role));

        withToken($token)->getJson('/api/v1/class-subjects')->assertOk();
        withToken($token)->postJson('/api/v1/class-subjects', classSubjectCreatePayload())->assertCreated();
    }
});

it('keeps class subjects away from a pupil account', function (): void {
    $token = loginAs(userWithRole(Role::STUDENT));

    withToken($token)->getJson('/api/v1/class-subjects')->assertForbidden();
    withToken($token)->postJson('/api/v1/class-subjects', classSubjectCreatePayload())->assertForbidden();
});

it('requires a specific permission per action rather than one blanket grant', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $classSubject = activeClassSubject();

    $admin->role->permissions()->detach(
        Permission::query()->where('name', 'class_subjects.create')->value('id')
    );

    $token = loginAs($admin);

    withToken($token)->getJson('/api/v1/class-subjects')->assertOk();
    withToken($token)->postJson('/api/v1/class-subjects', classSubjectCreatePayload())->assertForbidden();
    withToken($token)->putJson("/api/v1/class-subjects/{$classSubject->id}", ['status' => 'INACTIVE'])->assertOk();
});

it('seeds the permissions this module owns and no others', function (): void {
    $names = Permission::query()->where('name', 'like', 'class_subjects.%')->pluck('name')->all();

    expect($names)->toEqualCanonicalizing(['class_subjects.view', 'class_subjects.create', 'class_subjects.update']);
});

/*
| Filters
*/

it('filters by class', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    $target = activeClassSubject();
    activeClassSubject();

    withToken($token)->getJson("/api/v1/class-subjects?school_class_id={$target->school_class_id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $target->id);
});

it('filters by subject', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    $target = activeClassSubject();
    activeClassSubject();

    withToken($token)->getJson("/api/v1/class-subjects?subject_id={$target->subject_id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $target->id);
});

it('filters by status', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    activeClassSubject();
    $inactive = activeClassSubject(['status' => CatalogStatus::INACTIVE]);

    withToken($token)->getJson('/api/v1/class-subjects?status=INACTIVE')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $inactive->id);
});

it('rejects a search parameter, because there is no text field to search', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    withToken($token)->getJson('/api/v1/class-subjects?search=anything')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('search');
});

it('rejects a filter naming a class that does not exist', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    withToken($token)->getJson('/api/v1/class-subjects?school_class_id=999999')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('school_class_id');
});

it('returns an empty list rather than an error when nothing matches', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    activeClassSubject();

    withToken($token)->getJson('/api/v1/class-subjects?status=INACTIVE')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('rejects invalid pagination', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    withToken($token)->getJson('/api/v1/class-subjects?per_page=0')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('per_page');
});

/*
| What the API will not do or show
*/

it('ignores a mass-assignment attempt to inject status on create', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    $response = withToken($token)->postJson('/api/v1/class-subjects', classSubjectCreatePayload(['status' => 'INACTIVE']));

    $response->assertCreated();

    expect(ClassSubject::query()->sole()->status)->toBe(CatalogStatus::ACTIVE);
});

it('does not let one class subject be read or amended through another one\'s id (IDOR)', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $mine = activeClassSubject();
    $someoneElses = activeClassSubject();

    withToken($token)->putJson("/api/v1/class-subjects/{$mine->id}", ['status' => 'INACTIVE'])->assertOk();

    expect($mine->refresh()->status)->toBe(CatalogStatus::INACTIVE)
        ->and($someoneElses->refresh()->status)->toBe(CatalogStatus::ACTIVE);
});

it('lists class subjects through the same envelope as every other list in the API', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    activeClassSubject();

    withToken($token)->getJson('/api/v1/class-subjects')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [['id', 'school_class', 'subject', 'status']],
            'meta' => ['current_page', 'per_page', 'total', 'last_page'],
        ]);
});

it('refuses a suspended account, even with a token minted before the suspension', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);

    $admin->forceFill(['status' => UserStatus::SUSPENDED])->save();
    forgetResolvedUser();

    withToken($token)->getJson('/api/v1/class-subjects')->assertForbidden();
});

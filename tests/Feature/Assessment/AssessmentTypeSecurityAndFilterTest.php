<?php

use App\Enums\CatalogStatus;
use App\Enums\Role;
use App\Enums\StaffType;
use App\Enums\UserStatus;
use App\Models\AssessmentType;
use App\Models\Permission;
use App\Models\Role as RoleModel;

/*
|--------------------------------------------------------------------------
| Assessment type authorization, filters and exposure (Module 09)
|--------------------------------------------------------------------------
*/

/*
| Authorization
*/

it('refuses assessment types to an unauthenticated caller', function (): void {
    catalogAssessmentType();

    test()->getJson('/api/v1/assessment-types')->assertUnauthorized();
    test()->postJson('/api/v1/assessment-types', assessmentTypeCreatePayload())->assertUnauthorized();
});

it('refuses assessment types to a user with no assessment_types permission', function (): void {
    $teacher = userWithRole(Role::STAFF);
    $token = loginAs($teacher);

    withToken($token)->getJson('/api/v1/assessment-types')->assertForbidden();
    withToken($token)->postJson('/api/v1/assessment-types', assessmentTypeCreatePayload())->assertForbidden();
});

it('refuses a non-teaching staff member the same way as a teaching one', function (): void {
    $staffMember = staffMember(StaffType::NON_TEACHING);
    $token = loginAs($staffMember->user);

    withToken($token)->postJson('/api/v1/assessment-types', assessmentTypeCreatePayload())->assertForbidden();
});

it('refuses assessment types to a suspended account, even with a token minted before the suspension', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);

    $admin->forceFill(['status' => UserStatus::SUSPENDED])->save();
    forgetResolvedUser();

    withToken($token)->getJson('/api/v1/assessment-types')->assertForbidden();
});

it('grants assessment types to super admin, admin and registrar', function (): void {
    foreach ([Role::SUPER_ADMIN, Role::ADMIN, Role::REGISTRAR] as $role) {
        $token = loginAs(userWithRole($role));

        withToken($token)->getJson('/api/v1/assessment-types')->assertOk();
        withToken($token)->postJson('/api/v1/assessment-types', assessmentTypeCreatePayload())->assertCreated();
    }
});

it('keeps assessment types away from a pupil account', function (): void {
    $token = loginAs(userWithRole(Role::STUDENT));

    withToken($token)->getJson('/api/v1/assessment-types')->assertForbidden();
    withToken($token)->postJson('/api/v1/assessment-types', assessmentTypeCreatePayload())->assertForbidden();
});

it('withholds delete from registrar, matching the curriculum-structure permission split', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $assessmentType = catalogAssessmentType();

    withToken($token)->deleteJson("/api/v1/assessment-types/{$assessmentType->id}")->assertForbidden();

    expect(AssessmentType::query()->whereKey($assessmentType->id)->exists())->toBeTrue();
});

it('grants delete to admin and super admin', function (): void {
    foreach ([Role::SUPER_ADMIN, Role::ADMIN] as $role) {
        $assessmentType = catalogAssessmentType();
        $token = loginAs(userWithRole($role));

        withToken($token)->deleteJson("/api/v1/assessment-types/{$assessmentType->id}")->assertOk();
    }
});

it('requires a specific permission per action rather than one blanket grant', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $assessmentType = catalogAssessmentType();

    $admin->role->permissions()->detach(
        Permission::query()->where('name', 'assessment_types.create')->value('id')
    );

    $token = loginAs($admin);

    withToken($token)->getJson('/api/v1/assessment-types')->assertOk();
    withToken($token)->postJson('/api/v1/assessment-types', assessmentTypeCreatePayload())->assertForbidden();
    withToken($token)->putJson("/api/v1/assessment-types/{$assessmentType->id}", assessmentTypeUpdatePayload())->assertOk();
});

it('seeds the permissions this module owns and no others', function (): void {
    $names = Permission::query()->where('name', 'like', 'assessment_types.%')->pluck('name')->all();

    expect($names)->toEqualCanonicalizing([
        'assessment_types.view', 'assessment_types.create', 'assessment_types.update', 'assessment_types.delete',
    ]);
});

it('adds the module permissions without revoking the earlier modules', function (): void {
    $admin = RoleModel::query()->where('name', Role::ADMIN->value)->firstOrFail();
    $held = $admin->permissions()->pluck('name')->all();

    expect($held)->toContain('school.view')
        ->toContain('staff.view')
        ->toContain('students.view')
        ->toContain('admissions.view')
        ->toContain('enrollments.view')
        ->toContain('subjects.view')
        ->toContain('teacher_assignments.view')
        ->toContain('assessment_types.view');
});

/*
| Filters
*/

it('finds an assessment type by name', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    $target = catalogAssessmentType(['name' => 'Continuous Assessment']);
    catalogAssessmentType(['name' => 'Examination']);

    withToken($token)->getJson('/api/v1/assessment-types?search=continuous')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $target->id);
});

it('finds an assessment type by an exact code', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    $target = catalogAssessmentType(['code' => 'CA']);
    catalogAssessmentType(['code' => 'EXM']);

    withToken($token)->getJson('/api/v1/assessment-types?code=ca')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $target->id);
});

it('filters by status', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    catalogAssessmentType(['status' => CatalogStatus::ACTIVE]);
    $inactive = catalogAssessmentType(['status' => CatalogStatus::INACTIVE]);

    withToken($token)->getJson('/api/v1/assessment-types?status=INACTIVE')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $inactive->id);
});

it('filters to only active assessment types with active_only', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    $active = catalogAssessmentType(['status' => CatalogStatus::ACTIVE]);
    catalogAssessmentType(['status' => CatalogStatus::ARCHIVED]);

    withToken($token)->getJson('/api/v1/assessment-types?active_only=true')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $active->id);
});

it('rejects a status filter value the enum does not define', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    withToken($token)->getJson('/api/v1/assessment-types?status=DRAFT')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');
});

it('rejects invalid pagination', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    withToken($token)->getJson('/api/v1/assessment-types?per_page=0')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('per_page');
});

/*
| What the API will not do or show
*/

it('ignores a mass-assignment attempt to inject an id or timestamps', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    $response = withToken($token)->postJson('/api/v1/assessment-types', assessmentTypeCreatePayload([
        'id' => 999999,
        'created_at' => '2000-01-01T00:00:00Z',
    ]));

    $response->assertCreated();

    expect($response->json('data.id'))->not->toBe(999999)
        ->and(AssessmentType::query()->sole()->created_at->year)->not->toBe(2000);
});

it('lists assessment types through the same envelope as every other list in the API', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    catalogAssessmentType();

    withToken($token)->getJson('/api/v1/assessment-types')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [['id', 'name', 'code', 'sort_order', 'status']],
            'meta' => ['current_page', 'per_page', 'total', 'last_page'],
        ]);
});

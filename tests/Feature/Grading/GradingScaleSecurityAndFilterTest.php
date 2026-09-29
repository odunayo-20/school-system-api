<?php

use App\Enums\CatalogStatus;
use App\Enums\Role;
use App\Enums\StaffType;
use App\Enums\UserStatus;
use App\Models\ClassLevel;
use App\Models\GradingScale;
use App\Models\Permission;
use App\Models\Role as RoleModel;

/*
|--------------------------------------------------------------------------
| Grading scale authorization, filters and exposure (Module 11)
|--------------------------------------------------------------------------
*/

/*
| Authorization
*/

it('refuses grading scales to an unauthenticated caller', function (): void {
    configuredGradingScale();

    test()->getJson('/api/v1/grading-scales')->assertUnauthorized();
    test()->postJson('/api/v1/grading-scales', gradingScaleCreatePayload())->assertUnauthorized();
});

it('refuses grading scales to a user with no grading_scales permission', function (): void {
    $teacher = userWithRole(Role::STAFF);
    $token = loginAs($teacher);

    withToken($token)->getJson('/api/v1/grading-scales')->assertForbidden();
    withToken($token)->postJson('/api/v1/grading-scales', gradingScaleCreatePayload())->assertForbidden();
});

it('refuses configuration changes to teaching staff, even though they enter scores', function (): void {
    // The brief's own explicit boundary: entering scores (Module 10) never implies a say over
    // what those scores mean.
    $teacher = staffMember(StaffType::TEACHING);
    $token = loginAs($teacher->user);

    withToken($token)->postJson('/api/v1/grading-scales', gradingScaleCreatePayload())->assertForbidden();
});

it('keeps grading scales away from a pupil account', function (): void {
    $token = loginAs(userWithRole(Role::STUDENT));

    withToken($token)->getJson('/api/v1/grading-scales')->assertForbidden();
    withToken($token)->postJson('/api/v1/grading-scales', gradingScaleCreatePayload())->assertForbidden();
});

it('refuses a suspended account, even with a token minted before the suspension', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);

    $admin->forceFill(['status' => UserStatus::SUSPENDED])->save();
    forgetResolvedUser();

    withToken($token)->getJson('/api/v1/grading-scales')->assertForbidden();
});

it('grants full access to super admin and admin', function (): void {
    foreach ([Role::SUPER_ADMIN, Role::ADMIN] as $role) {
        $token = loginAs(userWithRole($role));

        withToken($token)->getJson('/api/v1/grading-scales')->assertOk();
        withToken($token)->postJson('/api/v1/grading-scales', gradingScaleCreatePayload())->assertCreated();
    }
});

it('grants view only to registrar, matching the academic-policy permission split', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $scale = configuredGradingScale();

    withToken($token)->getJson('/api/v1/grading-scales')->assertOk();
    withToken($token)->getJson("/api/v1/grading-scales/{$scale->id}")->assertOk();
    withToken($token)->postJson("/api/v1/grading-scales/{$scale->id}/calculate", ['percentage' => 85])->assertOk();
    withToken($token)->postJson('/api/v1/grading-scales', gradingScaleCreatePayload())->assertForbidden();
    withToken($token)->putJson("/api/v1/grading-scales/{$scale->id}", gradingScaleUpdatePayload())->assertForbidden();
});

it('requires a specific permission per action rather than one blanket grant', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $scale = configuredGradingScale();

    $admin->role->permissions()->detach(
        Permission::query()->where('name', 'grading_scales.create')->value('id')
    );

    $token = loginAs($admin);

    withToken($token)->getJson('/api/v1/grading-scales')->assertOk();
    withToken($token)->postJson('/api/v1/grading-scales', gradingScaleCreatePayload())->assertForbidden();
    withToken($token)->putJson("/api/v1/grading-scales/{$scale->id}", gradingScaleUpdatePayload())->assertOk();
});

it('seeds the permissions this module owns and no others', function (): void {
    $names = Permission::query()->where('name', 'like', 'grading_scales.%')->pluck('name')->all();

    expect($names)->toEqualCanonicalizing(['grading_scales.view', 'grading_scales.create', 'grading_scales.update']);
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
        ->toContain('assessments.view')
        ->toContain('scores.view')
        ->toContain('grading_scales.view');
});

/*
| Filters
*/

it('filters by class level', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    $target = configuredGradingScale();
    configuredGradingScale();

    withToken($token)->getJson("/api/v1/grading-scales?class_level_id={$target->class_level_id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $target->id);
});

it('filters by status', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    configuredGradingScale();
    $inactive = configuredGradingScale(['status' => CatalogStatus::INACTIVE]);

    withToken($token)->getJson('/api/v1/grading-scales?status=INACTIVE')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $inactive->id);
});

it('filters to only active scales with active_only', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    $active = configuredGradingScale();
    configuredGradingScale(['status' => CatalogStatus::ARCHIVED]);

    withToken($token)->getJson('/api/v1/grading-scales?active_only=true')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $active->id);
});

it('finds a grading scale by name', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    $target = configuredGradingScale(['name' => 'Senior Secondary WAEC Style']);
    configuredGradingScale(['name' => 'Primary Basic']);

    withToken($token)->getJson('/api/v1/grading-scales?search=waec')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $target->id);
});

it('rejects a status filter value the enum does not define', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    withToken($token)->getJson('/api/v1/grading-scales?status=DRAFT')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');
});

it('rejects a filter naming a class level that does not exist', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    withToken($token)->getJson('/api/v1/grading-scales?class_level_id=999999')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('class_level_id');
});

it('rejects invalid pagination', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    withToken($token)->getJson('/api/v1/grading-scales?per_page=0')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('per_page');
});

/*
| What the API will not do or show
*/

it('ignores a mass-assignment attempt to inject status or active_marker on create', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    $response = withToken($token)->postJson('/api/v1/grading-scales', gradingScaleCreatePayload([
        'status' => 'INACTIVE',
        'active_marker' => null,
    ]));

    $response->assertCreated()->assertJsonPath('data.status', CatalogStatus::ACTIVE->value);

    expect(GradingScale::query()->sole()->active_marker)->toBeTrue();
});

it('does not let one grading scale be read or amended through another one\'s id (IDOR)', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $mine = configuredGradingScale();
    $someoneElses = configuredGradingScale();

    withToken($token)->putJson("/api/v1/grading-scales/{$mine->id}", gradingScaleUpdatePayload(['name' => 'Renamed']))
        ->assertOk();

    expect($mine->refresh()->name)->toBe('Renamed')
        ->and($someoneElses->refresh()->name)->not->toBe('Renamed');
});

it('lists grading scales through the same envelope as every other list in the API', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    configuredGradingScale();

    withToken($token)->getJson('/api/v1/grading-scales')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [['id', 'name', 'code', 'sort_order', 'status', 'class_level']],
            'meta' => ['current_page', 'per_page', 'total', 'last_page'],
        ]);
});

it('cannot corrupt historical interpretation by naming an unrelated class level\'s scale', function (): void {
    // A student in class level A must never have their percentage interpreted against a
    // scale scoped to an unrelated class level B - verified here at the resource level: a
    // scale never reports a class_level other than its own immutable one.
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $levelA = ClassLevel::factory()->create();
    $levelB = ClassLevel::factory()->create();
    $scaleA = configuredGradingScale(['class_level_id' => $levelA->id]);
    configuredGradingScale(['class_level_id' => $levelB->id]);

    withToken($token)->getJson("/api/v1/grading-scales/{$scaleA->id}")
        ->assertOk()
        ->assertJsonPath('data.class_level.id', $levelA->id);
});

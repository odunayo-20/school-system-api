<?php

use App\Enums\EnrollmentStatus;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\Enrollment;
use App\Models\Permission;
use App\Models\Role as RoleModel;

/*
|--------------------------------------------------------------------------
| Enrollment authorization, filters and exposure (Module 06)
|--------------------------------------------------------------------------
*/

/*
| Authorization
*/

it('refuses enrollments to an unauthenticated caller', function (): void {
    activeEnrollment();

    test()->getJson('/api/v1/enrollments')->assertUnauthorized();
    test()->postJson('/api/v1/enrollments', enrollmentCreatePayload())->assertUnauthorized();
});

it('refuses enrollments to a user with no enrollments permission', function (): void {
    $teacher = userWithRole(Role::STAFF);
    $token = loginAs($teacher);

    withToken($token)->getJson('/api/v1/enrollments')->assertForbidden();
    withToken($token)->postJson('/api/v1/enrollments', enrollmentCreatePayload())->assertForbidden();
});

it('refuses enrollments to a suspended account, even with a token minted before the suspension', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);

    $admin->forceFill(['status' => UserStatus::SUSPENDED])->save();
    forgetResolvedUser();

    withToken($token)->getJson('/api/v1/enrollments')->assertForbidden();
});

it('grants enrollments to super admin, admin and registrar', function (): void {
    foreach ([Role::SUPER_ADMIN, Role::ADMIN, Role::REGISTRAR] as $role) {
        $token = loginAs(userWithRole($role));

        withToken($token)->getJson('/api/v1/enrollments')->assertOk();
        withToken($token)->postJson('/api/v1/enrollments', enrollmentCreatePayload())->assertCreated();
    }
});

it('keeps enrollments away from a pupil account and a staff account', function (): void {
    foreach ([Role::STUDENT, Role::STAFF] as $role) {
        $token = loginAs(userWithRole($role));

        withToken($token)->getJson('/api/v1/enrollments')->assertForbidden();
        withToken($token)->postJson('/api/v1/enrollments', enrollmentCreatePayload())->assertForbidden();
    }
});

it('requires a specific permission per action rather than one blanket grant', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $enrollment = activeEnrollment();

    $admin->role->permissions()->detach(
        Permission::query()->where('name', 'enrollments.create')->value('id')
    );

    $token = loginAs($admin);

    withToken($token)->getJson('/api/v1/enrollments')->assertOk();
    withToken($token)->postJson('/api/v1/enrollments', enrollmentCreatePayload())->assertForbidden();
    withToken($token)->putJson("/api/v1/enrollments/{$enrollment->id}", enrollmentUpdatePayload([
        'enrollment_date' => $enrollment->academicSession->start_date->toDateString(),
    ]))->assertOk();
});

it('separates the withdraw permission from update and view', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $enrollment = activeEnrollment();

    $admin->role->permissions()->detach(
        Permission::query()->where('name', 'enrollments.withdraw')->value('id')
    );

    $token = loginAs($admin);

    withToken($token)->getJson('/api/v1/enrollments')->assertOk();
    withToken($token)->postJson("/api/v1/enrollments/{$enrollment->id}/withdraw")->assertForbidden();

    expect($enrollment->refresh()->status)->toBe(EnrollmentStatus::ACTIVE);
});

it('seeds the permissions this module owns and no others', function (): void {
    $names = Permission::query()->where('name', 'like', 'enrollments.%')->pluck('name')->all();

    expect($names)->toEqualCanonicalizing([
        'enrollments.view', 'enrollments.create', 'enrollments.update',
        'enrollments.withdraw', 'enrollments.cancel',
    ]);
});

it('adds the module permissions without revoking the earlier modules', function (): void {
    $admin = RoleModel::query()->where('name', Role::ADMIN->value)->firstOrFail();
    $held = $admin->permissions()->pluck('name')->all();

    expect($held)->toContain('school.view')          // Module 01/02
        ->toContain('staff.view')                    // Module 03
        ->toContain('students.view')                 // Module 04
        ->toContain('admissions.view')                // Module 05
        ->toContain('enrollments.view')               // Module 06
        ->not->toContain('enrollments.delete');
});

/*
| Filters
*/

it('filters by student', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    $target = activeEnrollment();
    activeEnrollment();

    withToken($token)->getJson("/api/v1/enrollments?student_id={$target->student_id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $target->id);
});

it('filters by academic session', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    $target = activeEnrollment();
    activeEnrollment();

    withToken($token)->getJson("/api/v1/enrollments?academic_session_id={$target->academic_session_id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $target->id);
});

it('filters by class and by section, answering a class roster question', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    $section = activeSection();
    $inClass = activeEnrollment(['school_class_id' => $section->school_class_id, 'section_id' => $section->id]);
    activeEnrollment();

    withToken($token)->getJson("/api/v1/enrollments?school_class_id={$section->school_class_id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $inClass->id);

    withToken($token)->getJson("/api/v1/enrollments?section_id={$section->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $inClass->id);
});

it('filters by status', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    activeEnrollment();
    $withdrawn = decidedEnrollment(EnrollmentStatus::WITHDRAWN);

    withToken($token)->getJson('/api/v1/enrollments?status=WITHDRAWN')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $withdrawn->id);
});

it('rejects a status filter value the enum does not define, naming the permitted values', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    withToken($token)->getJson('/api/v1/enrollments?status=SOMETHING_ELSE')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status')
        ->assertJsonPath('errors.status.0', 'The status must be one of: ACTIVE, WITHDRAWN, CANCELLED.');
});

it('rejects a search parameter, because there is no text field to search', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    withToken($token)->getJson('/api/v1/enrollments?search=anything')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('search');
});

it('rejects a filter naming a student that does not exist', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    withToken($token)->getJson('/api/v1/enrollments?student_id=999999')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('student_id');
});

it('returns an empty list rather than an error when nothing matches', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    activeEnrollment();

    withToken($token)->getJson('/api/v1/enrollments?status=CANCELLED')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('pages the enrollment list and caps the page size', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    Enrollment::factory()->count(25)->create();

    $first = withToken($token)->getJson('/api/v1/enrollments?per_page=10&page=1');
    $first->assertOk()->assertJsonCount(10, 'data');

    expect($first->json('meta.total'))->toBe(25)
        ->and($first->json('meta.last_page'))->toBe(3);

    withToken($token)->getJson('/api/v1/enrollments?per_page=500')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('per_page');
});

it('rejects invalid pagination', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    withToken($token)->getJson('/api/v1/enrollments?per_page=0')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('per_page');
});

/*
| What the API will not do or show
*/

it('ignores a mass-assignment attempt to inject status, or to repoint the placement, on create', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $someoneElsesSection = activeSection();

    $response = withToken($token)->postJson('/api/v1/enrollments', enrollmentCreatePayload([
        'status' => 'CANCELLED',
    ]));

    $response->assertCreated();

    expect(Enrollment::query()->sole()->status)->toBe(EnrollmentStatus::ACTIVE);
});

it('does not let one enrollment be read or amended through another enrollment\'s id (IDOR)', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $mine = activeEnrollment();
    $someoneElses = activeEnrollment(['notes' => 'Unchanged']);

    withToken($token)->putJson("/api/v1/enrollments/{$mine->id}", enrollmentUpdatePayload([
        'enrollment_date' => $mine->academicSession->start_date->toDateString(),
        'notes' => 'Changed',
    ]))->assertOk();

    expect($mine->refresh()->notes)->toBe('Changed')
        ->and($someoneElses->refresh()->notes)->toBe('Unchanged');
});

it('exposes no account internals through the nested student', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $enrollment = activeEnrollment();

    $response = withToken($token)->getJson("/api/v1/enrollments/{$enrollment->id}");

    $response->assertOk();

    expect(array_keys($response->json('data.student')))
        ->toBe([
            'id', 'student_number',
            'first_name', 'middle_name', 'last_name', 'full_name',
            'date_of_birth', 'gender',
            'status', 'account_status',
            'created_at', 'updated_at',
        ]);
});

it('lists enrollments through the same envelope as every other list in the API', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    activeEnrollment();

    withToken($token)->getJson('/api/v1/enrollments')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [['id', 'student', 'academic_session', 'school_class', 'section', 'status']],
            'meta' => ['current_page', 'per_page', 'total', 'last_page'],
        ]);
});

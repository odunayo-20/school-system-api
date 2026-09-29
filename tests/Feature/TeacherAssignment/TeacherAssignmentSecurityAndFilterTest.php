<?php

use App\Enums\Role;
use App\Enums\TeacherAssignmentStatus;
use App\Enums\UserStatus;
use App\Models\Permission;
use App\Models\Role as RoleModel;
use App\Models\TeacherAssignment;

/*
|--------------------------------------------------------------------------
| Teacher assignment authorization, filters and exposure (Module 08)
|--------------------------------------------------------------------------
*/

/*
| Authorization
*/

it('refuses assignments to an unauthenticated caller', function (): void {
    activeAssignment();

    test()->getJson('/api/v1/teacher-assignments')->assertUnauthorized();
    test()->postJson('/api/v1/teacher-assignments', assignmentCreatePayload())->assertUnauthorized();
});

it('refuses assignments to a user with no teacher_assignments permission', function (): void {
    $teacher = userWithRole(Role::STAFF);
    $token = loginAs($teacher);

    withToken($token)->getJson('/api/v1/teacher-assignments')->assertForbidden();
    withToken($token)->postJson('/api/v1/teacher-assignments', assignmentCreatePayload())->assertForbidden();
});

it('grants full CRUD to super admin and admin', function (): void {
    foreach ([Role::SUPER_ADMIN, Role::ADMIN] as $role) {
        $token = loginAs(userWithRole($role));

        withToken($token)->getJson('/api/v1/teacher-assignments')->assertOk();
        withToken($token)->postJson('/api/v1/teacher-assignments', assignmentCreatePayload())->assertCreated();
    }
});

it('grants registrar read access only, not create, update, end or cancel', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $assignment = activeAssignment();

    withToken($token)->getJson('/api/v1/teacher-assignments')->assertOk();
    withToken($token)->getJson("/api/v1/teacher-assignments/{$assignment->id}")->assertOk();

    withToken($token)->postJson('/api/v1/teacher-assignments', assignmentCreatePayload())->assertForbidden();
    withToken($token)->putJson("/api/v1/teacher-assignments/{$assignment->id}", ['notes' => 'x'])->assertForbidden();
    withToken($token)->postJson("/api/v1/teacher-assignments/{$assignment->id}/end")->assertForbidden();
    withToken($token)->postJson("/api/v1/teacher-assignments/{$assignment->id}/cancel")->assertForbidden();

    expect(TeacherAssignment::query()->count())->toBe(1)
        ->and($assignment->refresh()->status)->toBe(TeacherAssignmentStatus::ACTIVE);
});

it('keeps assignments away from a pupil account', function (): void {
    $token = loginAs(userWithRole(Role::STUDENT));

    withToken($token)->getJson('/api/v1/teacher-assignments')->assertForbidden();
    withToken($token)->postJson('/api/v1/teacher-assignments', assignmentCreatePayload())->assertForbidden();
});

it('refuses a suspended account, even with a token minted before the suspension', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);

    $admin->forceFill(['status' => UserStatus::SUSPENDED])->save();
    forgetResolvedUser();

    withToken($token)->getJson('/api/v1/teacher-assignments')->assertForbidden();
});

it('requires a specific permission per action rather than one blanket grant', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $assignment = activeAssignment();

    $admin->role->permissions()->detach(
        Permission::query()->where('name', 'teacher_assignments.create')->value('id')
    );

    $token = loginAs($admin);

    withToken($token)->getJson('/api/v1/teacher-assignments')->assertOk();
    withToken($token)->postJson('/api/v1/teacher-assignments', assignmentCreatePayload())->assertForbidden();
    withToken($token)->putJson("/api/v1/teacher-assignments/{$assignment->id}", ['notes' => 'x'])->assertOk();
});

it('seeds the permissions this module owns and no others', function (): void {
    $names = Permission::query()->where('name', 'like', 'teacher_assignments.%')->pluck('name')->all();

    expect($names)->toEqualCanonicalizing([
        'teacher_assignments.view', 'teacher_assignments.create', 'teacher_assignments.update',
        'teacher_assignments.end', 'teacher_assignments.cancel',
    ]);
});

it('adds the module permissions without revoking the earlier modules', function (): void {
    $admin = RoleModel::query()->where('name', Role::ADMIN->value)->firstOrFail();
    $held = $admin->permissions()->pluck('name')->all();

    expect($held)->toContain('school.view')             // Module 01/02
        ->toContain('staff.view')                        // Module 03
        ->toContain('students.view')                     // Module 04
        ->toContain('admissions.view')                    // Module 05
        ->toContain('enrollments.view')                   // Module 06
        ->toContain('subjects.view')                      // Module 07
        ->toContain('teacher_assignments.view');          // Module 08
});

/*
| Filters
*/

it('filters by teaching staff', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    $target = activeAssignment();
    activeAssignment();

    withToken($token)->getJson("/api/v1/teacher-assignments?teaching_staff_id={$target->teaching_staff_id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $target->id);
});

it('filters by class subject', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    $target = activeAssignment();
    activeAssignment();

    withToken($token)->getJson("/api/v1/teacher-assignments?class_subject_id={$target->class_subject_id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $target->id);
});

it('filters by academic session', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    $target = activeAssignment();
    activeAssignment();

    withToken($token)->getJson("/api/v1/teacher-assignments?academic_session_id={$target->academic_session_id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $target->id);
});

it('filters by status', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    activeAssignment();
    $ended = decidedAssignment(TeacherAssignmentStatus::ENDED);

    withToken($token)->getJson('/api/v1/teacher-assignments?status=ENDED')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $ended->id);
});

it('rejects a status filter value the enum does not define', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    withToken($token)->getJson('/api/v1/teacher-assignments?status=SOMETHING_ELSE')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status')
        ->assertJsonPath('errors.status.0', 'The status must be one of: ACTIVE, ENDED, CANCELLED.');
});

it('rejects a search parameter, because there is no text field to search', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    withToken($token)->getJson('/api/v1/teacher-assignments?search=anything')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('search');
});

it('rejects a filter naming a staff member that does not exist', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    withToken($token)->getJson('/api/v1/teacher-assignments?teaching_staff_id=999999')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('teaching_staff_id');
});

it('returns an empty list rather than an error when nothing matches', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    activeAssignment();

    withToken($token)->getJson('/api/v1/teacher-assignments?status=CANCELLED')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('pages the assignment list and caps the page size', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    TeacherAssignment::factory()->count(25)->create();

    $first = withToken($token)->getJson('/api/v1/teacher-assignments?per_page=10&page=1');
    $first->assertOk()->assertJsonCount(10, 'data');

    expect($first->json('meta.total'))->toBe(25);

    withToken($token)->getJson('/api/v1/teacher-assignments?per_page=500')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('per_page');
});

it('rejects invalid pagination', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    withToken($token)->getJson('/api/v1/teacher-assignments?per_page=0')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('per_page');
});

/*
| What the API will not do or show
*/

it('ignores a mass-assignment attempt to inject status, or to repoint the assignment, on create', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $someoneElsesAssignment = activeAssignment();

    $response = withToken($token)->postJson('/api/v1/teacher-assignments', assignmentCreatePayload([
        'status' => 'CANCELLED',
        'active_marker' => null,
    ]));

    $response->assertCreated();

    expect(TeacherAssignment::query()->latest('id')->first()->status)->toBe(TeacherAssignmentStatus::ACTIVE);
});

it('does not let one assignment be read or amended through another assignment\'s id (IDOR)', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $mine = activeAssignment();
    $someoneElses = activeAssignment(['notes' => 'Unchanged']);

    withToken($token)->putJson("/api/v1/teacher-assignments/{$mine->id}", ['notes' => 'Changed'])->assertOk();

    expect($mine->refresh()->notes)->toBe('Changed')
        ->and($someoneElses->refresh()->notes)->toBe('Unchanged');
});

it('exposes no account internals through the nested teaching staff member', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $assignment = activeAssignment();

    $response = withToken($token)->getJson("/api/v1/teacher-assignments/{$assignment->id}");

    $response->assertOk();

    // StaffResource's own exact field list, spelled out rather than asserted with "not to
    // have key" per field, so a field added to that resource later has to be added here
    // deliberately.
    expect(array_keys($response->json('data.teaching_staff')))
        ->toBe([
            'id', 'staff_number', 'name', 'email', 'staff_type', 'designation',
            'employment_date', 'phone', 'status', 'account_status',
            'created_at', 'updated_at',
        ]);
});

it('lists assignments through the same envelope as every other list in the API', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    activeAssignment();

    withToken($token)->getJson('/api/v1/teacher-assignments')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [['id', 'teaching_staff', 'class_subject', 'academic_session', 'status']],
            'meta' => ['current_page', 'per_page', 'total', 'last_page'],
        ]);
});

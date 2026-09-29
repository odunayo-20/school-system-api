<?php

use App\Enums\Role;
use App\Enums\StaffType;
use App\Enums\UserStatus;
use App\Models\Permission;
use App\Models\Result;
use App\Models\Role as RoleModel;
use App\Models\TeacherAssignment;

/*
|--------------------------------------------------------------------------
| Result authorization, teacher-assignment scoping and filters (Module 12)
|--------------------------------------------------------------------------
*/

/*
| Authorization: the base permission gate
*/

it('refuses results to an unauthenticated caller', function (): void {
    $result = compiledResult();

    test()->getJson('/api/v1/results')->assertUnauthorized();
    test()->getJson("/api/v1/results/{$result->id}")->assertUnauthorized();
    test()->postJson('/api/v1/results/compile', compileResultPayload())->assertUnauthorized();
});

it('refuses results to a suspended account, even with a token minted before the suspension', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);

    $admin->forceFill(['status' => UserStatus::SUSPENDED])->save();
    forgetResolvedUser();

    withToken($token)->getJson('/api/v1/results')->assertForbidden();
});

it('grants full access to super admin, admin and registrar', function (): void {
    foreach ([Role::SUPER_ADMIN, Role::ADMIN, Role::REGISTRAR] as $role) {
        $token = loginAs(userWithRole($role));

        withToken($token)->getJson('/api/v1/results')->assertOk();
        withToken($token)->postJson('/api/v1/results/compile', compileResultPayload())->assertOk();
    }
});

it('keeps results away from a pupil account', function (): void {
    $token = loginAs(userWithRole(Role::STUDENT));

    withToken($token)->getJson('/api/v1/results')->assertForbidden();
    withToken($token)->postJson('/api/v1/results/compile', compileResultPayload())->assertForbidden();
});

it('requires a specific permission per action rather than one blanket grant', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $result = compiledResult($admin);

    $admin->role->permissions()->detach(
        Permission::query()->where('name', 'results.compile')->value('id')
    );

    $token = loginAs($admin);

    withToken($token)->getJson('/api/v1/results')->assertOk();
    withToken($token)->getJson("/api/v1/results/{$result->id}")->assertOk();
    withToken($token)->postJson('/api/v1/results/compile', compileResultPayload())->assertForbidden();
});

it('seeds the permissions this module owns and no others', function (): void {
    $names = Permission::query()->where('name', 'like', 'results.%')->pluck('name')->all();

    expect($names)->toEqualCanonicalizing([
        'results.view', 'results.compile', 'results.submit',
        'results.approve', 'results.publish', 'results.lock',
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
        ->toContain('assessments.view')
        ->toContain('scores.view')
        ->toContain('grading_scales.view')
        ->toContain('results.view');
});

/*
| Teacher assignment authorization: the core of this module
*/

it('lets an assigned teacher compile a result for their own class subject', function (): void {
    [$assessment, $enrollment] = resultCompilationContext();
    $session = $assessment->term->academicSession;
    $teacher = teacherAssignedTo($assessment->classSubject, $session);

    $token = loginAs($teacher->user);

    withToken($token)->postJson('/api/v1/results/compile', [
        'enrollment_id' => $enrollment->id,
        'class_subject_id' => $assessment->class_subject_id,
        'term_id' => $assessment->term_id,
    ])->assertOk();
});

it('refuses a teacher who is not assigned to the class subject - a Mathematics teacher must not compile English results', function (): void {
    [$assessment, $enrollment] = resultCompilationContext();
    $unrelatedTeacher = eligibleTeacher();

    $token = loginAs($unrelatedTeacher->user);

    withToken($token)->postJson('/api/v1/results/compile', [
        'enrollment_id' => $enrollment->id,
        'class_subject_id' => $assessment->class_subject_id,
        'term_id' => $assessment->term_id,
    ])->assertForbidden();

    expect(Result::query()->count())->toBe(0);
});

it('refuses a teacher whose assignment to this class subject has already ended', function (): void {
    [$assessment, $enrollment] = resultCompilationContext();
    $session = $assessment->term->academicSession;
    $teacher = eligibleTeacher();

    TeacherAssignment::factory()
        ->forTeacher($teacher)
        ->forClassSubject($assessment->classSubject)
        ->forSession($session)
        ->ended()
        ->create();

    $token = loginAs($teacher->user);

    withToken($token)->postJson('/api/v1/results/compile', [
        'enrollment_id' => $enrollment->id,
        'class_subject_id' => $assessment->class_subject_id,
        'term_id' => $assessment->term_id,
    ])->assertForbidden();
});

it('refuses a teacher\'s assignment to a DIFFERENT academic session than the term', function (): void {
    [$assessment, $enrollment] = resultCompilationContext();
    $teacher = eligibleTeacher();

    // Assigned to the right class subject, but for an unrelated session - a teacher assigned
    // to one class/session must not compile another.
    TeacherAssignment::factory()
        ->forTeacher($teacher)
        ->forClassSubject($assessment->classSubject)
        ->create();

    $token = loginAs($teacher->user);

    withToken($token)->postJson('/api/v1/results/compile', [
        'enrollment_id' => $enrollment->id,
        'class_subject_id' => $assessment->class_subject_id,
        'term_id' => $assessment->term_id,
    ])->assertForbidden();
});

it('refuses a non-teaching staff member even if they somehow hold the permission', function (): void {
    [$assessment, $enrollment] = resultCompilationContext();
    $staffMember = staffMember(StaffType::NON_TEACHING);

    $token = loginAs($staffMember->user);

    withToken($token)->getJson('/api/v1/results')->assertOk()->assertJsonCount(0, 'data');
    withToken($token)->postJson('/api/v1/results/compile', [
        'enrollment_id' => $enrollment->id,
        'class_subject_id' => $assessment->class_subject_id,
        'term_id' => $assessment->term_id,
    ])->assertForbidden();
});

it('scopes an assigned teacher\'s list to only the class subjects they are assigned to', function (): void {
    $adminA = userWithRole(Role::ADMIN);
    $resultA = compiledResult($adminA);
    compiledResult($adminA);

    $teacher = teacherAssignedTo($resultA->classSubject, $resultA->term->academicSession);

    $token = loginAs($teacher->user);

    withToken($token)->getJson('/api/v1/results')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $resultA->id);
});

it('refuses an unassigned teacher reading a result by id (IDOR)', function (): void {
    $result = compiledResult();
    $unrelatedTeacher = eligibleTeacher();

    $token = loginAs($unrelatedTeacher->user);

    withToken($token)->getJson("/api/v1/results/{$result->id}")->assertForbidden();
});

it('a teacher cannot bypass their own scope simply by naming another class subject\'s result in a filter', function (): void {
    $adminA = userWithRole(Role::ADMIN);
    $resultA = compiledResult($adminA);
    $outsideResult = compiledResult($adminA);

    $teacher = teacherAssignedTo($resultA->classSubject, $resultA->term->academicSession);

    $token = loginAs($teacher->user);

    // Filtering by an id entirely outside the teacher's own scope must return nothing, never
    // the result itself - the scope is applied before any filter, not replaced by one.
    withToken($token)->getJson("/api/v1/results?class_subject_id={$outsideResult->class_subject_id}")
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

/*
| Filters
*/

it('filters by enrollment', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    $target = compiledResult();
    compiledResult();

    withToken($token)->getJson("/api/v1/results?enrollment_id={$target->enrollment_id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $target->id);
});

it('filters by class subject', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    $target = compiledResult();
    compiledResult();

    withToken($token)->getJson("/api/v1/results?class_subject_id={$target->class_subject_id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $target->id);
});

it('filters by term', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    $target = compiledResult();
    compiledResult();

    withToken($token)->getJson("/api/v1/results?term_id={$target->term_id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $target->id);
});

it('filters by student', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    $target = compiledResult();
    compiledResult();

    withToken($token)->getJson("/api/v1/results?student_id={$target->enrollment->student_id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $target->id);
});

it('filters by academic session', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    $target = compiledResult();
    compiledResult();

    withToken($token)->getJson("/api/v1/results?academic_session_id={$target->enrollment->academic_session_id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $target->id);
});

it('filters by status', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    $incomplete = Result::factory()->incomplete()->create();
    compiledResult();

    withToken($token)->getJson('/api/v1/results?status=INCOMPLETE')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $incomplete->id);
});

it('rejects a status filter value the enum does not define', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    withToken($token)->getJson('/api/v1/results?status=BOGUS')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');
});

it('rejects a search parameter, because there is no text field to search', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    withToken($token)->getJson('/api/v1/results?search=anything')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('search');
});

it('rejects a filter naming a class subject that does not exist', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    withToken($token)->getJson('/api/v1/results?class_subject_id=999999')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('class_subject_id');
});

it('returns an empty list rather than an error when a valid filter matches nothing', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    compiledResult();
    $unrelatedClassSubject = activeClassSubject();

    withToken($token)->getJson("/api/v1/results?class_subject_id={$unrelatedClassSubject->id}")
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('rejects invalid pagination', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    withToken($token)->getJson('/api/v1/results?per_page=0')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('per_page');
});

/*
| What the API will not do or show
*/

it('cannot bypass the server-calculated percentage, grade or grade point by sending them in the compile payload', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    [$assessment, $enrollment] = resultCompilationContext(['max_score' => 20], [], score: 10);

    $response = withToken($token)->postJson('/api/v1/results/compile', [
        'enrollment_id' => $enrollment->id,
        'class_subject_id' => $assessment->class_subject_id,
        'term_id' => $assessment->term_id,
        'percentage' => 100,
        'grade' => 'A',
        'grade_point' => 5,
        'status' => 'COMPILED',
    ]);

    $response->assertOk();

    // 10/20*100 = 50, the server-derived value - never the client-supplied 100.
    expect($response->json('data.percentage'))->toBe('50.00');
});

it('lists results through the same envelope as every other list in the API', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    compiledResult();

    withToken($token)->getJson('/api/v1/results')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [['id', 'enrollment', 'class_subject', 'term', 'percentage', 'grade', 'grade_point', 'remark', 'status']],
            'meta' => ['current_page', 'per_page', 'total', 'last_page'],
        ]);
});

it('exposes no account internals through the nested student', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $result = compiledResult();

    $response = withToken($token)->getJson("/api/v1/results/{$result->id}")->assertOk();

    expect($response->json('data.enrollment.student'))
        ->not->toHaveKey('email')
        ->not->toHaveKey('password');
});

it('answers 405 for a PUT or PATCH, because a result is written only through compile', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $result = compiledResult();

    withToken($token)->putJson("/api/v1/results/{$result->id}", ['percentage' => 100])->assertStatus(405);
    withToken($token)->patchJson("/api/v1/results/{$result->id}", ['percentage' => 100])->assertStatus(405);
});

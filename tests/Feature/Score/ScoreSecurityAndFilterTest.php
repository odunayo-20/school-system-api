<?php

use App\Enums\Role;
use App\Enums\StaffType;
use App\Enums\UserStatus;
use App\Models\Permission;
use App\Models\Role as RoleModel;
use App\Models\Score;
use App\Models\TeacherAssignment;

/*
|--------------------------------------------------------------------------
| Score authorization, teacher-assignment scoping and filters (Module 10)
|--------------------------------------------------------------------------
*/

/*
| Authorization: the base permission gate
*/

it('refuses scores to an unauthenticated caller', function (): void {
    recordedScore();

    test()->getJson('/api/v1/scores')->assertUnauthorized();
    test()->postJson('/api/v1/scores', scoreCreatePayload())->assertUnauthorized();
});

it('refuses scores to a suspended account, even with a token minted before the suspension', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);

    $admin->forceFill(['status' => UserStatus::SUSPENDED])->save();
    forgetResolvedUser();

    withToken($token)->getJson('/api/v1/scores')->assertForbidden();
});

it('grants full access to super admin and admin', function (): void {
    foreach ([Role::SUPER_ADMIN, Role::ADMIN] as $role) {
        $token = loginAs(userWithRole($role));

        withToken($token)->getJson('/api/v1/scores')->assertOk();
        withToken($token)->postJson('/api/v1/scores', scoreCreatePayload())->assertCreated();
    }
});

it('grants view only to registrar, matching the teaching-domain permission split', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $score = recordedScore();

    withToken($token)->getJson('/api/v1/scores')->assertOk();
    withToken($token)->getJson("/api/v1/scores/{$score->id}")->assertOk();
    withToken($token)->postJson('/api/v1/scores', scoreCreatePayload())->assertForbidden();
    withToken($token)->putJson("/api/v1/scores/{$score->id}", scoreUpdatePayload())->assertForbidden();
});

it('keeps scores away from a pupil account', function (): void {
    $token = loginAs(userWithRole(Role::STUDENT));

    withToken($token)->getJson('/api/v1/scores')->assertForbidden();
    withToken($token)->postJson('/api/v1/scores', scoreCreatePayload())->assertForbidden();
});

it('requires a specific permission per action rather than one blanket grant', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $score = recordedScore();

    $admin->role->permissions()->detach(
        Permission::query()->where('name', 'scores.create')->value('id')
    );

    $token = loginAs($admin);

    withToken($token)->getJson('/api/v1/scores')->assertOk();
    withToken($token)->postJson('/api/v1/scores', scoreCreatePayload())->assertForbidden();
    withToken($token)->putJson("/api/v1/scores/{$score->id}", scoreUpdatePayload())->assertOk();
});

it('seeds the permissions this module owns and no others', function (): void {
    $names = Permission::query()->where('name', 'like', 'scores.%')->pluck('name')->all();

    expect($names)->toEqualCanonicalizing(['scores.view', 'scores.create', 'scores.update']);
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
        ->toContain('scores.view');
});

/*
| Teacher assignment authorization: the core of this module
*/

it('lets an assigned teacher record a score for their own class subject', function (): void {
    [$assessment, $enrollment] = matchedScoreContext();
    $session = $assessment->term->academicSession;
    $teacher = teacherAssignedTo($assessment->classSubject, $session);

    $token = loginAs($teacher->user);

    withToken($token)->postJson('/api/v1/scores', scoreCreatePayload([
        'assessment_id' => $assessment->id,
        'enrollment_id' => $enrollment->id,
    ]))->assertCreated();
});

it('refuses a teacher who is not assigned to the assessment\'s class subject', function (): void {
    // Teacher A teaches JSS 2 Mathematics; the assessment here belongs to an unrelated class
    // subject Teacher A holds no assignment for at all - the exact scenario the brief names.
    [$assessment, $enrollment] = matchedScoreContext();
    $unrelatedTeacher = eligibleTeacher();

    $token = loginAs($unrelatedTeacher->user);

    withToken($token)->postJson('/api/v1/scores', scoreCreatePayload([
        'assessment_id' => $assessment->id,
        'enrollment_id' => $enrollment->id,
    ]))->assertForbidden();

    expect(Score::query()->count())->toBe(0);
});

it('refuses a teacher whose assignment to this class subject has already ended', function (): void {
    [$assessment, $enrollment] = matchedScoreContext();
    $session = $assessment->term->academicSession;
    $teacher = eligibleTeacher();

    TeacherAssignment::factory()
        ->forTeacher($teacher)
        ->forClassSubject($assessment->classSubject)
        ->forSession($session)
        ->ended()
        ->create();

    $token = loginAs($teacher->user);

    withToken($token)->postJson('/api/v1/scores', scoreCreatePayload([
        'assessment_id' => $assessment->id,
        'enrollment_id' => $enrollment->id,
    ]))->assertForbidden();
});

it('refuses a teacher\'s assignment to a DIFFERENT academic session than the assessment', function (): void {
    [$assessment, $enrollment] = matchedScoreContext();
    $teacher = eligibleTeacher();

    // Assigned to the right class subject, but for an unrelated session - the assignment does
    // not cover the session this assessment's term actually falls in.
    TeacherAssignment::factory()
        ->forTeacher($teacher)
        ->forClassSubject($assessment->classSubject)
        ->create();

    $token = loginAs($teacher->user);

    withToken($token)->postJson('/api/v1/scores', scoreCreatePayload([
        'assessment_id' => $assessment->id,
        'enrollment_id' => $enrollment->id,
    ]))->assertForbidden();
});

it('refuses a non-teaching staff member even if they somehow hold the permission', function (): void {
    [$assessment, $enrollment] = matchedScoreContext();
    $staffMember = staffMember(StaffType::NON_TEACHING);

    $token = loginAs($staffMember->user);

    withToken($token)->getJson('/api/v1/scores')->assertOk()->assertJsonCount(0, 'data');
    withToken($token)->postJson('/api/v1/scores', scoreCreatePayload([
        'assessment_id' => $assessment->id,
        'enrollment_id' => $enrollment->id,
    ]))->assertForbidden();
});

it('scopes an assigned teacher\'s list to only the class subjects they are assigned to', function (): void {
    [$assessmentA, $enrollmentA] = matchedScoreContext();
    [$assessmentB, $enrollmentB] = matchedScoreContext();
    $teacher = teacherAssignedTo($assessmentA->classSubject, $assessmentA->term->academicSession);

    $scoreA = recordedScore(['assessment_id' => $assessmentA->id, 'enrollment_id' => $enrollmentA->id]);
    recordedScore(['assessment_id' => $assessmentB->id, 'enrollment_id' => $enrollmentB->id]);

    $token = loginAs($teacher->user);

    withToken($token)->getJson('/api/v1/scores')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $scoreA->id);
});

it('refuses an unassigned teacher reading a score by id (IDOR)', function (): void {
    [$assessment, $enrollment] = matchedScoreContext();
    $score = recordedScore(['assessment_id' => $assessment->id, 'enrollment_id' => $enrollment->id]);
    $unrelatedTeacher = eligibleTeacher();

    $token = loginAs($unrelatedTeacher->user);

    withToken($token)->getJson("/api/v1/scores/{$score->id}")->assertForbidden();
});

it('refuses an unassigned teacher amending a score by id (IDOR)', function (): void {
    [$assessment, $enrollment] = matchedScoreContext();
    $score = recordedScore(['assessment_id' => $assessment->id, 'enrollment_id' => $enrollment->id, 'score' => 10]);
    $unrelatedTeacher = eligibleTeacher();

    $token = loginAs($unrelatedTeacher->user);

    withToken($token)->putJson("/api/v1/scores/{$score->id}", scoreUpdatePayload(['score' => 19]))
        ->assertForbidden();

    expect((float) $score->refresh()->score)->toBe(10.0);
});

it('a teacher cannot bypass their own scope simply by naming another student\'s enrollment in a filter', function (): void {
    [$assessmentA, $enrollmentA] = matchedScoreContext();
    [$assessmentB, $enrollmentB] = matchedScoreContext();
    $teacher = teacherAssignedTo($assessmentA->classSubject, $assessmentA->term->academicSession);

    recordedScore(['assessment_id' => $assessmentA->id, 'enrollment_id' => $enrollmentA->id]);
    $outsideScore = recordedScore(['assessment_id' => $assessmentB->id, 'enrollment_id' => $enrollmentB->id]);

    $token = loginAs($teacher->user);

    // Filtering by an id entirely outside the teacher's own scope must return nothing, never
    // the score itself - the scope is applied before any filter, not replaced by one.
    withToken($token)->getJson("/api/v1/scores?enrollment_id={$outsideScore->enrollment_id}")
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

/*
| Filters
*/

it('filters by assessment', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    $target = recordedScore();
    recordedScore();

    withToken($token)->getJson("/api/v1/scores?assessment_id={$target->assessment_id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $target->id);
});

it('filters by enrollment', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    $target = recordedScore();
    recordedScore();

    withToken($token)->getJson("/api/v1/scores?enrollment_id={$target->enrollment_id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $target->id);
});

it('filters by student', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    $target = recordedScore();
    recordedScore();

    withToken($token)->getJson("/api/v1/scores?student_id={$target->enrollment->student_id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $target->id);
});

it('filters by academic session', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    $target = recordedScore();
    recordedScore();

    withToken($token)->getJson("/api/v1/scores?academic_session_id={$target->enrollment->academic_session_id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $target->id);
});

it('rejects a search parameter, because there is no text field to search', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    withToken($token)->getJson('/api/v1/scores?search=anything')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('search');
});

it('rejects a filter naming an assessment that does not exist', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    withToken($token)->getJson('/api/v1/scores?assessment_id=999999')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('assessment_id');
});

it('returns an empty list rather than an error when a valid filter matches nothing', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    recordedScore();
    $unrelatedAssessment = activeAssessment();

    withToken($token)->getJson("/api/v1/scores?assessment_id={$unrelatedAssessment->id}")
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('rejects invalid pagination', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    withToken($token)->getJson('/api/v1/scores?per_page=0')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('per_page');
});

/*
| What the API will not do or show
*/

it('ignores a mass-assignment attempt to inject an id or timestamps', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    $response = withToken($token)->postJson('/api/v1/scores', scoreCreatePayload([
        'id' => 999999,
        'created_at' => '2000-01-01T00:00:00Z',
    ]));

    $response->assertCreated();

    expect($response->json('data.id'))->not->toBe(999999)
        ->and(Score::query()->sole()->created_at->year)->not->toBe(2000);
});

it('lists scores through the same envelope as every other list in the API', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    recordedScore();

    withToken($token)->getJson('/api/v1/scores')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [['id', 'score', 'max_score', 'score_percentage', 'remarks', 'assessment', 'enrollment']],
            'meta' => ['current_page', 'per_page', 'total', 'last_page'],
        ]);
});

it('exposes no account internals through the nested student', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $score = recordedScore();

    $response = withToken($token)->getJson("/api/v1/scores/{$score->id}")->assertOk();

    expect($response->json('data.enrollment.student'))
        ->not->toHaveKey('email')
        ->not->toHaveKey('password');
});

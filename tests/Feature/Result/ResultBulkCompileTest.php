<?php

use App\Enums\EnrollmentStatus;
use App\Enums\Role;
use App\Models\Result;
use App\Models\Score;
use App\Models\Term;

/*
|--------------------------------------------------------------------------
| Bulk result compilation: one class subject and term, a whole roster (Module 12)
|--------------------------------------------------------------------------
|
| Deliberately NOT all-or-nothing, unlike Module 10's bulk score entry: each student's
| compile is an independent, idempotent calculation, so one student's INCOMPLETE status (or
| any other per-student failure) must never stop the rest of the class from compiling.
|
*/

it('compiles results for a whole class subject and term in one call', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    $class = selectableSchoolClass();
    $classSubject = activeClassSubject(['school_class_id' => $class->id]);
    $session = eligibleSession();
    $term = Term::factory()->forSession($session, 1)->create();
    $assessment = activeAssessment(['class_subject_id' => $classSubject->id, 'term_id' => $term->id, 'max_score' => 20]);

    $enrollmentIds = rosterEnrollments($assessment, 3);
    foreach ($enrollmentIds as $id) {
        Score::factory()->forAssessment($assessment)->create(['enrollment_id' => $id, 'score' => 15]);
    }

    $response = withToken($token)->postJson('/api/v1/results/bulk', [
        'class_subject_id' => $classSubject->id,
        'term_id' => $term->id,
    ]);

    $response->assertOk()->assertJsonCount(3, 'data');

    foreach ($response->json('data') as $row) {
        expect($row['error'])->toBeNull()
            ->and($row['result']['status'])->toBe('COMPILED');
    }

    expect(Result::query()->where('class_subject_id', $classSubject->id)->count())->toBe(3);
});

it('reports a student with no scores yet as INCOMPLETE without an error, and still compiles the rest of the class', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    $class = selectableSchoolClass();
    $classSubject = activeClassSubject(['school_class_id' => $class->id]);
    $session = eligibleSession();
    $term = Term::factory()->forSession($session, 1)->create();
    $assessment = activeAssessment(['class_subject_id' => $classSubject->id, 'term_id' => $term->id, 'max_score' => 20]);

    $enrollmentIds = rosterEnrollments($assessment, 2);
    Score::factory()->forAssessment($assessment)->create(['enrollment_id' => $enrollmentIds[0], 'score' => 15]);
    // enrollmentIds[1] gets no score at all.

    $response = withToken($token)->postJson('/api/v1/results/bulk', [
        'class_subject_id' => $classSubject->id,
        'term_id' => $term->id,
    ]);

    $response->assertOk()->assertJsonCount(2, 'data');

    $byEnrollment = collect($response->json('data'))->keyBy('enrollment_id');

    expect($byEnrollment[$enrollmentIds[0]]['result']['status'])->toBe('COMPILED')
        ->and($byEnrollment[$enrollmentIds[0]]['error'])->toBeNull()
        ->and($byEnrollment[$enrollmentIds[1]]['result']['status'])->toBe('INCOMPLETE')
        ->and($byEnrollment[$enrollmentIds[1]]['error'])->toBeNull();

    expect(Result::query()->where('class_subject_id', $classSubject->id)->count())->toBe(2);
});

it('only compiles currently ACTIVE enrollments in the class subject\'s own class and session', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    $class = selectableSchoolClass();
    $classSubject = activeClassSubject(['school_class_id' => $class->id]);
    $session = eligibleSession();
    $term = Term::factory()->forSession($session, 1)->create();
    $assessment = activeAssessment(['class_subject_id' => $classSubject->id, 'term_id' => $term->id]);

    $enrollmentIds = rosterEnrollments($assessment, 2);
    $withdrawn = activeEnrollment(['school_class_id' => $class->id, 'academic_session_id' => $session->id]);
    $withdrawn->forceFill(['status' => EnrollmentStatus::WITHDRAWN])->save();

    $response = withToken($token)->postJson('/api/v1/results/bulk', [
        'class_subject_id' => $classSubject->id,
        'term_id' => $term->id,
    ]);

    $response->assertOk()->assertJsonCount(2, 'data');

    $compiledEnrollmentIds = collect($response->json('data'))->pluck('enrollment_id')->all();
    expect($compiledEnrollmentIds)->not->toContain($withdrawn->id);
});

it('recompiling the same class subject and term updates existing rows rather than duplicating them', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    $class = selectableSchoolClass();
    $classSubject = activeClassSubject(['school_class_id' => $class->id]);
    $session = eligibleSession();
    $term = Term::factory()->forSession($session, 1)->create();
    $assessment = activeAssessment(['class_subject_id' => $classSubject->id, 'term_id' => $term->id, 'max_score' => 20]);

    $enrollmentIds = rosterEnrollments($assessment, 2);
    foreach ($enrollmentIds as $id) {
        Score::factory()->forAssessment($assessment)->create(['enrollment_id' => $id, 'score' => 10]);
    }

    withToken($token)->postJson('/api/v1/results/bulk', [
        'class_subject_id' => $classSubject->id,
        'term_id' => $term->id,
    ])->assertOk();

    withToken($token)->postJson('/api/v1/results/bulk', [
        'class_subject_id' => $classSubject->id,
        'term_id' => $term->id,
    ])->assertOk();

    expect(Result::query()->where('class_subject_id', $classSubject->id)->count())->toBe(2);
});

it('requires a class subject', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $term = Term::factory()->create();

    withToken($token)->postJson('/api/v1/results/bulk', [
        'term_id' => $term->id,
    ])->assertUnprocessable()->assertJsonValidationErrors('class_subject_id');
});

it('requires a term', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $classSubject = activeClassSubject();

    withToken($token)->postJson('/api/v1/results/bulk', [
        'class_subject_id' => $classSubject->id,
    ])->assertUnprocessable()->assertJsonValidationErrors('term_id');
});

it('refuses a class subject that does not exist or is not active', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $term = Term::factory()->create();

    withToken($token)->postJson('/api/v1/results/bulk', [
        'class_subject_id' => 999999,
        'term_id' => $term->id,
    ])->assertUnprocessable()->assertJsonValidationErrors('class_subject_id');
});

it('returns an empty result set rather than an error when the class has no active enrollments', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    $class = selectableSchoolClass();
    $classSubject = activeClassSubject(['school_class_id' => $class->id]);
    $session = eligibleSession();
    $term = Term::factory()->forSession($session, 1)->create();
    activeAssessment(['class_subject_id' => $classSubject->id, 'term_id' => $term->id]);

    withToken($token)->postJson('/api/v1/results/bulk', [
        'class_subject_id' => $classSubject->id,
        'term_id' => $term->id,
    ])->assertOk()->assertJsonCount(0, 'data');
});

/*
| Authorization
*/

it('refuses bulk compilation to an unauthenticated caller', function (): void {
    test()->postJson('/api/v1/results/bulk', ['class_subject_id' => 1, 'term_id' => 1])->assertUnauthorized();
});

it('refuses bulk compilation to a user with no results.compile permission', function (): void {
    $token = loginAs(userWithRole(Role::STUDENT));
    $classSubject = activeClassSubject();
    $term = Term::factory()->create();

    withToken($token)->postJson('/api/v1/results/bulk', [
        'class_subject_id' => $classSubject->id,
        'term_id' => $term->id,
    ])->assertForbidden();
});

it('lets an assigned teacher bulk-compile their own class subject', function (): void {
    $class = selectableSchoolClass();
    $classSubject = activeClassSubject(['school_class_id' => $class->id]);
    $session = eligibleSession();
    $term = Term::factory()->forSession($session, 1)->create();
    $assessment = activeAssessment(['class_subject_id' => $classSubject->id, 'term_id' => $term->id]);
    rosterEnrollments($assessment, 2);

    $teacher = teacherAssignedTo($classSubject, $session);
    $token = loginAs($teacher->user);

    withToken($token)->postJson('/api/v1/results/bulk', [
        'class_subject_id' => $classSubject->id,
        'term_id' => $term->id,
    ])->assertOk();
});

it('refuses an unassigned teacher bulk-compiling a class subject that is not theirs - a Mathematics teacher must not compile English results', function (): void {
    $class = selectableSchoolClass();
    $classSubject = activeClassSubject(['school_class_id' => $class->id]);
    $session = eligibleSession();
    $term = Term::factory()->forSession($session, 1)->create();
    $assessment = activeAssessment(['class_subject_id' => $classSubject->id, 'term_id' => $term->id]);
    rosterEnrollments($assessment, 2);

    $unrelatedTeacher = eligibleTeacher();
    $token = loginAs($unrelatedTeacher->user);

    withToken($token)->postJson('/api/v1/results/bulk', [
        'class_subject_id' => $classSubject->id,
        'term_id' => $term->id,
    ])->assertForbidden();

    expect(Result::query()->where('class_subject_id', $classSubject->id)->count())->toBe(0);
});

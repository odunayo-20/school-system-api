<?php

use App\Enums\CatalogStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\ResultStatus;
use App\Enums\Role;
use App\Enums\TermStatus;
use App\Models\ClassLevel;
use App\Models\GradingScale;
use App\Models\Result;
use App\Models\SchoolClass;
use App\Models\Score;
use App\Models\Section;
use App\Models\Term;

/*
|--------------------------------------------------------------------------
| Result compilation: the one calculation path (Module 12)
|--------------------------------------------------------------------------
*/

it('compiles a result from a single fully-scored assessment', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    [$assessment, $enrollment] = resultCompilationContext(['max_score' => 20], [], score: 17);

    $response = withToken($token)->postJson('/api/v1/results/compile', [
        'enrollment_id' => $enrollment->id,
        'class_subject_id' => $assessment->class_subject_id,
        'term_id' => $assessment->term_id,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.percentage', '85.00')
        ->assertJsonPath('data.status', ResultStatus::COMPILED->value)
        ->assertJsonPath('data.enrollment.id', $enrollment->id)
        ->assertJsonPath('data.class_subject.id', $assessment->class_subject_id);

    expect(Result::query()->count())->toBe(1);
});

it('sums each scored, weighted assessment\'s own contribution without renormalizing', function (): void {
    // (16/20)*40 + (48/60)*60 = 32 + 48 = 80 - the brief's own worked weighted example.
    $token = loginAs(userWithRole(Role::ADMIN));
    $class = selectableSchoolClass();
    $classSubject = activeClassSubject(['school_class_id' => $class->id]);
    $session = eligibleSession();
    $term = Term::factory()->forSession($session, 1)->create();
    $section = Section::factory()->within($class, 'A', 'A')->create();

    $ca = activeAssessment(['class_subject_id' => $classSubject->id, 'term_id' => $term->id, 'max_score' => 20, 'weight' => 40]);
    $exam = activeAssessment(['class_subject_id' => $classSubject->id, 'term_id' => $term->id, 'max_score' => 60, 'weight' => 60]);
    $enrollment = activeEnrollment(['school_class_id' => $class->id, 'section_id' => $section->id, 'academic_session_id' => $session->id]);

    Score::factory()->forAssessment($ca)->forEnrollment($enrollment)->create(['score' => 16]);
    Score::factory()->forAssessment($exam)->forEnrollment($enrollment)->create(['score' => 48]);

    withToken($token)->postJson('/api/v1/results/compile', [
        'enrollment_id' => $enrollment->id,
        'class_subject_id' => $classSubject->id,
        'term_id' => $term->id,
    ])->assertOk()->assertJsonPath('data.percentage', '80.00');
});

it('excludes a scored but unweighted assessment from a weighted calculation entirely', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $class = selectableSchoolClass();
    $classSubject = activeClassSubject(['school_class_id' => $class->id]);
    $session = eligibleSession();
    $term = Term::factory()->forSession($session, 1)->create();
    $section = Section::factory()->within($class, 'A', 'A')->create();

    $weighted = activeAssessment(['class_subject_id' => $classSubject->id, 'term_id' => $term->id, 'max_score' => 20, 'weight' => 40]);
    $unweighted = activeAssessment(['class_subject_id' => $classSubject->id, 'term_id' => $term->id, 'max_score' => 10, 'weight' => null]);
    $enrollment = activeEnrollment(['school_class_id' => $class->id, 'section_id' => $section->id, 'academic_session_id' => $session->id]);

    Score::factory()->forAssessment($weighted)->forEnrollment($enrollment)->create(['score' => 20]);
    Score::factory()->forAssessment($unweighted)->forEnrollment($enrollment)->create(['score' => 10]);

    // (20/20)*40 = 40 - the unweighted assessment's own 10/10 never enters the sum.
    withToken($token)->postJson('/api/v1/results/compile', [
        'enrollment_id' => $enrollment->id,
        'class_subject_id' => $classSubject->id,
        'term_id' => $term->id,
    ])->assertOk()->assertJsonPath('data.percentage', '40.00')
        // Both assessments were scored, so the result is still COMPLETE.
        ->assertJsonPath('data.status', ResultStatus::COMPILED->value);
});

it('falls back to a plain ratio when no assessment carries a configured weight', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $class = selectableSchoolClass();
    $classSubject = activeClassSubject(['school_class_id' => $class->id]);
    $session = eligibleSession();
    $term = Term::factory()->forSession($session, 1)->create();
    $section = Section::factory()->within($class, 'A', 'A')->create();

    $a1 = activeAssessment(['class_subject_id' => $classSubject->id, 'term_id' => $term->id, 'max_score' => 20, 'weight' => null]);
    $a2 = activeAssessment(['class_subject_id' => $classSubject->id, 'term_id' => $term->id, 'max_score' => 80, 'weight' => null]);
    $enrollment = activeEnrollment(['school_class_id' => $class->id, 'section_id' => $section->id, 'academic_session_id' => $session->id]);

    Score::factory()->forAssessment($a1)->forEnrollment($enrollment)->create(['score' => 18]);
    Score::factory()->forAssessment($a2)->forEnrollment($enrollment)->create(['score' => 72]);

    // (18 + 72) / (20 + 80) * 100 = 90.
    withToken($token)->postJson('/api/v1/results/compile', [
        'enrollment_id' => $enrollment->id,
        'class_subject_id' => $classSubject->id,
        'term_id' => $term->id,
    ])->assertOk()->assertJsonPath('data.percentage', '90.00');
});

it('leaves a partially scored result INCOMPLETE with no grade, but an honestly capped percentage', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $class = selectableSchoolClass();
    $classSubject = activeClassSubject(['school_class_id' => $class->id]);
    $session = eligibleSession();
    $term = Term::factory()->forSession($session, 1)->create();
    $section = Section::factory()->within($class, 'A', 'A')->create();

    $scored = activeAssessment(['class_subject_id' => $classSubject->id, 'term_id' => $term->id, 'max_score' => 20, 'weight' => 40]);
    activeAssessment(['class_subject_id' => $classSubject->id, 'term_id' => $term->id, 'max_score' => 60, 'weight' => 60]);
    $enrollment = activeEnrollment(['school_class_id' => $class->id, 'section_id' => $section->id, 'academic_session_id' => $session->id]);

    Score::factory()->forAssessment($scored)->forEnrollment($enrollment)->create(['score' => 16]);

    // (16/20)*40 = 32 - never renormalized against the missing 60-weight exam.
    withToken($token)->postJson('/api/v1/results/compile', [
        'enrollment_id' => $enrollment->id,
        'class_subject_id' => $classSubject->id,
        'term_id' => $term->id,
    ])->assertOk()
        ->assertJsonPath('data.percentage', '32.00')
        ->assertJsonPath('data.status', ResultStatus::INCOMPLETE->value)
        ->assertJsonPath('data.grade', null)
        ->assertJsonPath('data.grade_point', null)
        ->assertJsonPath('data.remark', null);
});

it('never treats a missing score as zero: no scores at all still yields a defined, capped percentage', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    [$assessment, $enrollment] = matchedScoreContext(['max_score' => 20, 'weight' => null]);

    withToken($token)->postJson('/api/v1/results/compile', [
        'enrollment_id' => $enrollment->id,
        'class_subject_id' => $assessment->class_subject_id,
        'term_id' => $assessment->term_id,
    ])->assertOk()
        ->assertJsonPath('data.percentage', '0.00')
        ->assertJsonPath('data.status', ResultStatus::INCOMPLETE->value);
});

it('refuses to compile a result when no assessments are configured for the class subject and term', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $class = selectableSchoolClass();
    $classSubject = activeClassSubject(['school_class_id' => $class->id]);
    $session = eligibleSession();
    $term = Term::factory()->forSession($session, 1)->create();
    $section = Section::factory()->within($class, 'A', 'A')->create();
    $enrollment = activeEnrollment(['school_class_id' => $class->id, 'section_id' => $section->id, 'academic_session_id' => $session->id]);

    withToken($token)->postJson('/api/v1/results/compile', [
        'enrollment_id' => $enrollment->id,
        'class_subject_id' => $classSubject->id,
        'term_id' => $term->id,
    ])->assertStatus(422);

    expect(Result::query()->count())->toBe(0);
});

it('resolves grade, grade point and remark through the active grading scale for the enrollment\'s class level', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $classLevel = ClassLevel::factory()->create();
    $class = SchoolClass::factory()->within($classLevel, 'JSS 1', 'JSS1')->create();
    GradingScale::factory()->configureWithStandardBands()->create(['class_level_id' => $classLevel->id]);

    $classSubject = activeClassSubject(['school_class_id' => $class->id]);
    $session = eligibleSession();
    $term = Term::factory()->forSession($session, 1)->create();
    $section = Section::factory()->within($class, 'A', 'A')->create();
    $assessment = activeAssessment(['class_subject_id' => $classSubject->id, 'term_id' => $term->id, 'max_score' => 20, 'weight' => null]);
    $enrollment = activeEnrollment(['school_class_id' => $class->id, 'section_id' => $section->id, 'academic_session_id' => $session->id]);

    Score::factory()->forAssessment($assessment)->forEnrollment($enrollment)->create(['score' => 15]);

    // 15/20*100 = 75 -> band A (70-100) on the standard five-band scale.
    withToken($token)->postJson('/api/v1/results/compile', [
        'enrollment_id' => $enrollment->id,
        'class_subject_id' => $classSubject->id,
        'term_id' => $term->id,
    ])->assertOk()
        ->assertJsonPath('data.percentage', '75.00')
        ->assertJsonPath('data.grade', 'A')
        ->assertJsonPath('data.grade_point', '5.00')
        ->assertJsonPath('data.remark', 'Excellent');
});

it('leaves grade, grade point and remark null for a complete result when no grading scale is configured', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    [$assessment, $enrollment] = resultCompilationContext(['max_score' => 20], [], score: 15);

    withToken($token)->postJson('/api/v1/results/compile', [
        'enrollment_id' => $enrollment->id,
        'class_subject_id' => $assessment->class_subject_id,
        'term_id' => $assessment->term_id,
    ])->assertOk()
        ->assertJsonPath('data.status', ResultStatus::COMPILED->value)
        ->assertJsonPath('data.grade', null)
        ->assertJsonPath('data.grade_point', null)
        ->assertJsonPath('data.remark', null);
});

/*
| Idempotent recompilation
*/

it('recompiling with no data change updates the same row rather than creating a duplicate', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    [$assessment, $enrollment] = resultCompilationContext(['max_score' => 20], [], score: 15);
    $payload = [
        'enrollment_id' => $enrollment->id,
        'class_subject_id' => $assessment->class_subject_id,
        'term_id' => $assessment->term_id,
    ];

    $first = withToken($token)->postJson('/api/v1/results/compile', $payload)->assertOk();
    $second = withToken($token)->postJson('/api/v1/results/compile', $payload)->assertOk();

    expect($first->json('data.id'))->toBe($second->json('data.id'))
        ->and(Result::query()->count())->toBe(1);
});

it('recompiles the same row with an updated percentage and grade after a score is corrected', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $classLevel = ClassLevel::factory()->create();
    $class = SchoolClass::factory()->within($classLevel, 'JSS 1', 'JSS1')->create();
    GradingScale::factory()->configureWithStandardBands()->create(['class_level_id' => $classLevel->id]);

    $classSubject = activeClassSubject(['school_class_id' => $class->id]);
    $session = eligibleSession();
    $term = Term::factory()->forSession($session, 1)->create();
    $section = Section::factory()->within($class, 'A', 'A')->create();
    $assessment = activeAssessment(['class_subject_id' => $classSubject->id, 'term_id' => $term->id, 'max_score' => 20, 'weight' => null]);
    $enrollment = activeEnrollment(['school_class_id' => $class->id, 'section_id' => $section->id, 'academic_session_id' => $session->id]);
    $score = Score::factory()->forAssessment($assessment)->forEnrollment($enrollment)->create(['score' => 10]);

    $payload = ['enrollment_id' => $enrollment->id, 'class_subject_id' => $classSubject->id, 'term_id' => $term->id];

    // 10/20*100 = 50 -> band C.
    $first = withToken($token)->postJson('/api/v1/results/compile', $payload)
        ->assertOk()
        ->assertJsonPath('data.percentage', '50.00')
        ->assertJsonPath('data.grade', 'C');

    $score->update(['score' => 18]);

    // 18/20*100 = 90 -> band A, same row.
    $second = withToken($token)->postJson('/api/v1/results/compile', $payload)
        ->assertOk()
        ->assertJsonPath('data.percentage', '90.00')
        ->assertJsonPath('data.grade', 'A');

    expect($first->json('data.id'))->toBe($second->json('data.id'))
        ->and(Result::query()->count())->toBe(1);
});

it('refuses to recompile a locked result', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    [$assessment, $enrollment] = resultCompilationContext();
    $result = Result::factory()
        ->forEnrollment($enrollment)
        ->forClassSubject($assessment->classSubject)
        ->forTerm($assessment->term)
        ->locked()
        ->create();

    withToken($token)->postJson('/api/v1/results/compile', [
        'enrollment_id' => $enrollment->id,
        'class_subject_id' => $assessment->class_subject_id,
        'term_id' => $assessment->term_id,
    ])->assertStatus(422);

    expect($result->refresh()->status)->toBe(ResultStatus::LOCKED);
});

/*
| Academic context integrity
*/

it('requires an enrollment', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    [$assessment] = resultCompilationContext();

    withToken($token)->postJson('/api/v1/results/compile', [
        'class_subject_id' => $assessment->class_subject_id,
        'term_id' => $assessment->term_id,
    ])->assertUnprocessable()->assertJsonValidationErrors('enrollment_id');
});

it('requires a class subject', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    [, $enrollment] = resultCompilationContext();

    withToken($token)->postJson('/api/v1/results/compile', [
        'enrollment_id' => $enrollment->id,
    ])->assertUnprocessable()->assertJsonValidationErrors(['class_subject_id', 'term_id']);
});

it('refuses a class subject that does not exist or is not active', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    withToken($token)->postJson('/api/v1/results/compile', compileResultPayload([
        'class_subject_id' => 999999,
    ]))->assertUnprocessable()->assertJsonValidationErrors('class_subject_id');
});

it('refuses an enrollment belonging to a different class than the class subject', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    [$assessment] = resultCompilationContext();
    [, $unrelatedEnrollment] = resultCompilationContext();

    withToken($token)->postJson('/api/v1/results/compile', [
        'enrollment_id' => $unrelatedEnrollment->id,
        'class_subject_id' => $assessment->class_subject_id,
        'term_id' => $assessment->term_id,
    ])->assertStatus(422);

    expect(Result::query()->count())->toBe(0);
});

it('refuses an enrollment belonging to a different academic session than the term', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $class = selectableSchoolClass();
    $classSubject = activeClassSubject(['school_class_id' => $class->id]);
    $section = Section::factory()->within($class, 'A', 'A')->create();

    $sessionA = eligibleSession();
    $termA = Term::factory()->forSession($sessionA, 1)->create();
    $assessment = activeAssessment(['class_subject_id' => $classSubject->id, 'term_id' => $termA->id]);

    $sessionB = eligibleSession();
    $enrollment = activeEnrollment([
        'school_class_id' => $class->id,
        'section_id' => $section->id,
        'academic_session_id' => $sessionB->id,
    ]);

    withToken($token)->postJson('/api/v1/results/compile', [
        'enrollment_id' => $enrollment->id,
        'class_subject_id' => $classSubject->id,
        'term_id' => $termA->id,
    ])->assertStatus(422);

    expect(Result::query()->count())->toBe(0);
});

it('refuses a class subject whose class level has been retired', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $retiredLevel = ClassLevel::factory()->status(CatalogStatus::ARCHIVED)->create();
    $class = SchoolClass::factory()->within($retiredLevel, 'JSS 1', 'JSS1')->create();
    $classSubject = activeClassSubject(['school_class_id' => $class->id]);
    $session = eligibleSession();
    $term = Term::factory()->forSession($session, 1)->create();
    $section = Section::factory()->within($class, 'A', 'A')->create();
    $assessment = activeAssessment(['class_subject_id' => $classSubject->id, 'term_id' => $term->id]);
    $enrollment = activeEnrollment(['school_class_id' => $class->id, 'section_id' => $section->id, 'academic_session_id' => $session->id]);

    withToken($token)->postJson('/api/v1/results/compile', [
        'enrollment_id' => $enrollment->id,
        'class_subject_id' => $classSubject->id,
        'term_id' => $term->id,
    ])->assertStatus(422);

    expect(Result::query()->count())->toBe(0);
});

it('does not require the enrollment to still be active - a withdrawn student\'s result remains compilable', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    [$assessment, $enrollment] = resultCompilationContext();
    $enrollment->forceFill(['status' => EnrollmentStatus::WITHDRAWN])->save();

    withToken($token)->postJson('/api/v1/results/compile', [
        'enrollment_id' => $enrollment->id,
        'class_subject_id' => $assessment->class_subject_id,
        'term_id' => $assessment->term_id,
    ])->assertOk();
});

it('does not require the term to still be open', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    [$assessment, $enrollment] = resultCompilationContext();
    $assessment->term->forceFill(['status' => TermStatus::COMPLETED])->save();

    withToken($token)->postJson('/api/v1/results/compile', [
        'enrollment_id' => $enrollment->id,
        'class_subject_id' => $assessment->class_subject_id,
        'term_id' => $assessment->term_id,
    ])->assertOk();
});

/*
| Read
*/

it('shows a single compiled result', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $result = compiledResult();

    withToken($token)->getJson("/api/v1/results/{$result->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $result->id);
});

it('answers 404 for a result that does not exist', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    withToken($token)->getJson('/api/v1/results/999999')->assertNotFound();
});

it('answers 405 for a delete, because a compiled result is never removed - only recompiled', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $result = compiledResult();

    withToken($token)->deleteJson("/api/v1/results/{$result->id}")->assertStatus(405);

    expect(Result::query()->whereKey($result->id)->exists())->toBeTrue();
});

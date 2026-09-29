<?php

use App\Enums\CatalogStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\Role;
use App\Enums\TermStatus;
use App\Models\ClassLevel;
use App\Models\SchoolClass;
use App\Models\Score;
use App\Models\Section;
use App\Models\Term;
use Database\Seeders\ScorePermissionSeeder;

/*
|--------------------------------------------------------------------------
| Score entry: create, read, amend (Module 10)
|--------------------------------------------------------------------------
*/

it('records a score', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    [$assessment, $enrollment] = matchedScoreContext(['max_score' => 20]);

    $response = withToken($token)->postJson('/api/v1/scores', scoreCreatePayload([
        'assessment_id' => $assessment->id,
        'enrollment_id' => $enrollment->id,
        'score' => 17,
    ]));

    $response->assertCreated()
        ->assertJsonPath('data.score', '17.00')
        ->assertJsonPath('data.max_score', '20.00')
        ->assertJsonPath('data.score_percentage', 85)
        ->assertJsonPath('data.assessment.id', $assessment->id)
        ->assertJsonPath('data.enrollment.id', $enrollment->id);

    expect(Score::query()->count())->toBe(1);
});

it('accepts a score of zero', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    [$assessment, $enrollment] = matchedScoreContext();

    withToken($token)->postJson('/api/v1/scores', scoreCreatePayload([
        'assessment_id' => $assessment->id,
        'enrollment_id' => $enrollment->id,
        'score' => 0,
    ]))->assertCreated()->assertJsonPath('data.score', '0.00');
});

it('accepts a score exactly equal to the assessment maximum', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    [$assessment, $enrollment] = matchedScoreContext(['max_score' => 20]);

    withToken($token)->postJson('/api/v1/scores', scoreCreatePayload([
        'assessment_id' => $assessment->id,
        'enrollment_id' => $enrollment->id,
        'score' => 20,
    ]))->assertCreated()->assertJsonPath('data.score', '20.00');
});

it('accepts a decimal score', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    [$assessment, $enrollment] = matchedScoreContext(['max_score' => 20]);

    withToken($token)->postJson('/api/v1/scores', scoreCreatePayload([
        'assessment_id' => $assessment->id,
        'enrollment_id' => $enrollment->id,
        'score' => 17.5,
    ]))->assertCreated()->assertJsonPath('data.score', '17.50');
});

it('refuses a score above the assessment maximum', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    [$assessment, $enrollment] = matchedScoreContext(['max_score' => 20]);

    withToken($token)->postJson('/api/v1/scores', scoreCreatePayload([
        'assessment_id' => $assessment->id,
        'enrollment_id' => $enrollment->id,
        'score' => 20.1,
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors('score')
        ->assertJsonPath('errors.score.0', "The score must not exceed the assessment's maximum score of 20.00.");

    expect(Score::query()->count())->toBe(0);
});

it('refuses a negative score', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    withToken($token)->postJson('/api/v1/scores', scoreCreatePayload(['score' => -1]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('score');

    expect(Score::query()->count())->toBe(0);
});

it('cannot bypass the assessment maximum by sending a maximum_score field of its own', function (): void {
    // The server determines the valid maximum from the assessment; nothing reads a client-
    // supplied one at all, so sending it is simply ignored rather than trusted.
    $token = loginAs(userWithRole(Role::ADMIN));
    [$assessment, $enrollment] = matchedScoreContext(['max_score' => 20]);

    withToken($token)->postJson('/api/v1/scores', scoreCreatePayload([
        'assessment_id' => $assessment->id,
        'enrollment_id' => $enrollment->id,
        'score' => 90,
        'maximum_score' => 100,
    ]))->assertUnprocessable()->assertJsonValidationErrors('score');

    expect(Score::query()->count())->toBe(0);
});

it('requires an assessment', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    $payload = scoreCreatePayload();
    unset($payload['assessment_id']);

    withToken($token)->postJson('/api/v1/scores', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('assessment_id');
});

it('requires an enrollment', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    $payload = scoreCreatePayload();
    unset($payload['enrollment_id']);

    withToken($token)->postJson('/api/v1/scores', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('enrollment_id');
});

it('refuses an assessment that does not exist', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    withToken($token)->postJson('/api/v1/scores', scoreCreatePayload(['assessment_id' => 999999]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('assessment_id');
});

it('refuses an assessment that has been retired', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    [$assessment, $enrollment] = matchedScoreContext(['status' => CatalogStatus::INACTIVE]);

    withToken($token)->postJson('/api/v1/scores', scoreCreatePayload([
        'assessment_id' => $assessment->id,
        'enrollment_id' => $enrollment->id,
    ]))->assertUnprocessable()->assertJsonValidationErrors('assessment_id');
});

it('refuses an enrollment that does not exist', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    withToken($token)->postJson('/api/v1/scores', scoreCreatePayload(['enrollment_id' => 999999]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('enrollment_id');
});

it('refuses an enrollment that has already ended', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    [$assessment, $enrollment] = matchedScoreContext();
    $enrollment->forceFill(['status' => EnrollmentStatus::WITHDRAWN])->save();

    withToken($token)->postJson('/api/v1/scores', scoreCreatePayload([
        'assessment_id' => $assessment->id,
        'enrollment_id' => $enrollment->id,
    ]))->assertUnprocessable()->assertJsonValidationErrors('enrollment_id');
});

it('refuses a duplicate score for the same assessment and enrollment', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    [$assessment, $enrollment] = matchedScoreContext();

    withToken($token)->postJson('/api/v1/scores', scoreCreatePayload([
        'assessment_id' => $assessment->id,
        'enrollment_id' => $enrollment->id,
    ]))->assertCreated();

    withToken($token)->postJson('/api/v1/scores', scoreCreatePayload([
        'assessment_id' => $assessment->id,
        'enrollment_id' => $enrollment->id,
    ]))->assertUnprocessable()->assertJsonValidationErrors('enrollment_id');

    expect(Score::query()->count())->toBe(1);
});

it('allows two different students to each have a score for the same assessment', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    [$assessment, $enrollmentA] = matchedScoreContext();

    // A second enrollment in the SAME class and session as the first, so it shares the
    // assessment's own academic context - matchedScoreContext() always builds a fresh class
    // and session together, so calling it twice would produce two UNRELATED contexts instead.
    $class = $assessment->classSubject->schoolClass;
    $session = $assessment->term->academicSession;
    $section = Section::factory()->within($class, 'B', 'B')->create();
    $enrollmentB = activeEnrollment([
        'school_class_id' => $class->id,
        'section_id' => $section->id,
        'academic_session_id' => $session->id,
    ]);

    withToken($token)->postJson('/api/v1/scores', scoreCreatePayload([
        'assessment_id' => $assessment->id,
        'enrollment_id' => $enrollmentA->id,
    ]))->assertCreated();

    withToken($token)->postJson('/api/v1/scores', scoreCreatePayload([
        'assessment_id' => $assessment->id,
        'enrollment_id' => $enrollmentB->id,
    ]))->assertCreated();

    expect(Score::query()->where('assessment_id', $assessment->id)->count())->toBe(2);
});

it('refuses an enrollment belonging to a different class than the assessment', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    [$assessment] = matchedScoreContext();
    [, $unrelatedEnrollment] = matchedScoreContext();

    withToken($token)->postJson('/api/v1/scores', scoreCreatePayload([
        'assessment_id' => $assessment->id,
        'enrollment_id' => $unrelatedEnrollment->id,
    ]))->assertStatus(422);

    expect(Score::query()->count())->toBe(0);
});

it('refuses an enrollment belonging to a different academic session than the assessment', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $class = selectableSchoolClass();
    $classSubject = activeClassSubject(['school_class_id' => $class->id]);
    $section = Section::factory()->within($class, 'A', 'A')->create();

    $sessionA = eligibleSession();
    $termA = Term::factory()->forSession($sessionA, 1)->create();
    $assessment = activeAssessment(['class_subject_id' => $classSubject->id, 'term_id' => $termA->id]);

    // Same class, but a DIFFERENT session - the JSS 1 2025/2026 vs JSS 2 2026/2027 scenario
    // the brief names explicitly.
    $sessionB = eligibleSession();
    $enrollment = activeEnrollment([
        'school_class_id' => $class->id,
        'section_id' => $section->id,
        'academic_session_id' => $sessionB->id,
    ]);

    withToken($token)->postJson('/api/v1/scores', scoreCreatePayload([
        'assessment_id' => $assessment->id,
        'enrollment_id' => $enrollment->id,
    ]))->assertStatus(422);

    expect(Score::query()->count())->toBe(0);
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

    withToken($token)->postJson('/api/v1/scores', scoreCreatePayload([
        'assessment_id' => $assessment->id,
        'enrollment_id' => $enrollment->id,
    ]))->assertStatus(422);

    expect(Score::query()->count())->toBe(0);
});

it('does not require the term to still be open - scoring a just-completed term is allowed', function (): void {
    // Deliberately different from every module that guards a NEW placement against a
    // completed session/term: a score is retrospective entry for an assessment that already
    // happened, routinely finished right as or after a term closes. See the Module 10 audit.
    $token = loginAs(userWithRole(Role::ADMIN));
    [$assessment, $enrollment] = matchedScoreContext();
    $assessment->term->forceFill(['status' => TermStatus::COMPLETED])->save();

    withToken($token)->postJson('/api/v1/scores', scoreCreatePayload([
        'assessment_id' => $assessment->id,
        'enrollment_id' => $enrollment->id,
    ]))->assertCreated();
});

it('shows a single score', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $score = recordedScore();

    withToken($token)->getJson("/api/v1/scores/{$score->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $score->id);
});

it('answers 404 for a score that does not exist', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    withToken($token)->getJson('/api/v1/scores/999999')->assertNotFound();
});

it('amends a score', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    [$assessment, $enrollment] = matchedScoreContext(['max_score' => 20]);
    $score = recordedScore(['assessment_id' => $assessment->id, 'enrollment_id' => $enrollment->id, 'score' => 15]);

    withToken($token)->putJson("/api/v1/scores/{$score->id}", scoreUpdatePayload([
        'score' => 18,
        'remarks' => 'Recomputed after a re-mark.',
    ]))->assertOk()
        ->assertJsonPath('data.score', '18.00')
        ->assertJsonPath('data.remarks', 'Recomputed after a re-mark.');

    expect((float) $score->refresh()->score)->toBe(18.0);
});

it('refuses an amended score above the assessment maximum', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    [$assessment, $enrollment] = matchedScoreContext(['max_score' => 20]);
    $score = recordedScore(['assessment_id' => $assessment->id, 'enrollment_id' => $enrollment->id, 'score' => 15]);

    withToken($token)->putJson("/api/v1/scores/{$score->id}", scoreUpdatePayload(['score' => 25]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('score');

    expect((float) $score->refresh()->score)->toBe(15.0);
});

it('re-validates an amend against the assessment live maximum, not the value at creation time', function (): void {
    // Assessment.max_score is itself editable through Module 09 - a score already on file is
    // never retroactively invalidated by that, but the NEXT write is checked against the
    // current value. See ScoreService::update().
    $token = loginAs(userWithRole(Role::ADMIN));
    [$assessment, $enrollment] = matchedScoreContext(['max_score' => 20]);
    $score = recordedScore(['assessment_id' => $assessment->id, 'enrollment_id' => $enrollment->id, 'score' => 18]);

    withToken($token)->putJson("/api/v1/assessments/{$assessment->id}", [
        'name' => $assessment->name,
        'max_score' => 10,
    ])->assertOk();

    withToken($token)->putJson("/api/v1/scores/{$score->id}", scoreUpdatePayload(['score' => 18]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('score');
});

it('cannot reach assessment_id or enrollment_id through the amend endpoint', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $score = recordedScore();
    $otherAssessment = activeAssessment();
    $otherEnrollment = activeEnrollment();

    withToken($token)->putJson("/api/v1/scores/{$score->id}", array_merge(scoreUpdatePayload(), [
        'assessment_id' => $otherAssessment->id,
        'enrollment_id' => $otherEnrollment->id,
    ]))->assertOk();

    $score->refresh();

    expect($score->assessment_id)->not->toBe($otherAssessment->id)
        ->and($score->enrollment_id)->not->toBe($otherEnrollment->id);
});

it('answers 405 for a PATCH', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $score = recordedScore();

    withToken($token)->patchJson("/api/v1/scores/{$score->id}", scoreUpdatePayload())
        ->assertStatus(405)
        ->assertHeader('Allow');
});

it('refuses a delete outright, because a score is the anchor for future grading records', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $score = recordedScore();

    withToken($token)->deleteJson("/api/v1/scores/{$score->id}")->assertStatus(405);

    expect(Score::query()->whereKey($score->id)->exists())->toBeTrue();
});

it('has no delete permission to grant in the first place', function (): void {
    expect(ScorePermissionSeeder::names())
        ->toContain('scores.view', 'scores.create', 'scores.update')
        ->not->toContain('scores.delete');
});

<?php

use App\Enums\AcademicSessionStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\PromotionDecision;
use App\Enums\Role;
use App\Enums\StudentStatus;
use App\Models\Enrollment;
use App\Models\Promotion;
use App\Models\Score;
use App\Models\Term;
use App\Services\Promotion\PromotionService;
use App\Services\Result\ResultService;

/*
|--------------------------------------------------------------------------
| Student promotion: PROMOTED, RETAINED, GRADUATED, validation, and
| historical safety (Module 15)
|--------------------------------------------------------------------------
*/

/*
| PROMOTED
*/

it('promotes a student to a different class and section for the target session', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $context = promotionContext();

    $response = withToken($token)->postJson(
        "/api/v1/students/{$context['enrollment']->student_id}/promote",
        promotePayload($context)
    );

    $response->assertCreated()
        ->assertJsonPath('data.decision', PromotionDecision::PROMOTED->value)
        ->assertJsonPath('data.source_enrollment.id', $context['enrollment']->id)
        ->assertJsonPath('data.target_enrollment.school_class.id', $context['targetClass']->id)
        ->assertJsonPath('data.target_enrollment.section.id', $context['targetSection']->id)
        ->assertJsonPath('data.target_academic_session.id', $context['targetSession']->id)
        ->assertJsonPath('data.decided_by.id', fn (int $id): bool => $id > 0)
        ->assertJsonPath('data.decided_at', fn (?string $at): bool => $at !== null);

    expect(Promotion::query()->count())->toBe(1)
        ->and(Enrollment::query()->count())->toBe(2);
});

it('creates the target enrollment with the correct session, class and section', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $context = promotionContext();

    $response = withToken($token)->postJson(
        "/api/v1/students/{$context['enrollment']->student_id}/promote",
        promotePayload($context)
    )->assertCreated();

    $targetEnrollmentId = $response->json('data.target_enrollment.id');
    $targetEnrollment = Enrollment::query()->findOrFail($targetEnrollmentId);

    expect($targetEnrollment->student_id)->toBe($context['enrollment']->student_id)
        ->and($targetEnrollment->academic_session_id)->toBe($context['targetSession']->id)
        ->and($targetEnrollment->school_class_id)->toBe($context['targetClass']->id)
        ->and($targetEnrollment->section_id)->toBe($context['targetSection']->id)
        ->and($targetEnrollment->status)->toBe(EnrollmentStatus::ACTIVE)
        ->and($targetEnrollment->enrollment_date->toDateString())->toBe($context['targetSession']->start_date->toDateString());
});

it('leaves the source enrollment completely untouched and historical - still ACTIVE, still pointing at the old class', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $context = promotionContext();
    $sourceEnrollment = $context['enrollment'];
    $originalAttributes = $sourceEnrollment->getAttributes();

    withToken($token)->postJson(
        "/api/v1/students/{$sourceEnrollment->student_id}/promote",
        promotePayload($context)
    )->assertCreated();

    $sourceEnrollment->refresh();

    expect($sourceEnrollment->status)->toBe(EnrollmentStatus::ACTIVE)
        ->and($sourceEnrollment->school_class_id)->toBe($originalAttributes['school_class_id'])
        ->and($sourceEnrollment->section_id)->toBe($originalAttributes['section_id'])
        ->and($sourceEnrollment->academic_session_id)->toBe($originalAttributes['academic_session_id']);
});

it('refuses a PROMOTED decision that targets the same class as the source enrollment', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $context = promotionContext();

    withToken($token)->postJson(
        "/api/v1/students/{$context['enrollment']->student_id}/promote",
        promotePayload($context, [
            'target_school_class_id' => $context['sourceClass']->id,
            'target_section_id' => $context['sourceSection']->id,
        ])
    )->assertStatus(422);

    expect(Promotion::query()->count())->toBe(0);
});

/*
| RETAINED
*/

it('retains a student in the same class and section for the target session', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $context = promotionContext();

    $response = withToken($token)->postJson(
        "/api/v1/students/{$context['enrollment']->student_id}/promote",
        [
            'source_enrollment_id' => $context['enrollment']->id,
            'target_academic_session_id' => $context['targetSession']->id,
            'decision' => PromotionDecision::RETAINED->value,
        ]
    );

    $response->assertCreated()
        ->assertJsonPath('data.decision', PromotionDecision::RETAINED->value)
        ->assertJsonPath('data.target_enrollment.school_class.id', $context['sourceClass']->id)
        ->assertJsonPath('data.target_enrollment.section.id', $context['sourceSection']->id);
});

it('ignores any target class/section a client tries to send for RETAINED - always the source\'s own', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $context = promotionContext();

    // target_school_class_id/target_section_id are PROHIBITED for RETAINED - sending them at
    // all is a validation failure, not a silently-ignored value.
    withToken($token)->postJson(
        "/api/v1/students/{$context['enrollment']->student_id}/promote",
        [
            'source_enrollment_id' => $context['enrollment']->id,
            'target_academic_session_id' => $context['targetSession']->id,
            'decision' => PromotionDecision::RETAINED->value,
            'target_school_class_id' => $context['targetClass']->id,
            'target_section_id' => $context['targetSection']->id,
        ]
    )->assertUnprocessable()->assertJsonValidationErrors(['target_school_class_id', 'target_section_id']);
});

it('leaves source history intact after a RETAINED decision', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $context = promotionContext();

    withToken($token)->postJson(
        "/api/v1/students/{$context['enrollment']->student_id}/promote",
        [
            'source_enrollment_id' => $context['enrollment']->id,
            'target_academic_session_id' => $context['targetSession']->id,
            'decision' => PromotionDecision::RETAINED->value,
        ]
    )->assertCreated();

    expect($context['enrollment']->refresh()->status)->toBe(EnrollmentStatus::ACTIVE)
        ->and(Enrollment::query()->count())->toBe(2);
});

/*
| GRADUATED
*/

it('graduates a student with no target class, creating no target enrollment', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $context = promotionContext();

    $response = withToken($token)->postJson(
        "/api/v1/students/{$context['enrollment']->student_id}/promote",
        [
            'source_enrollment_id' => $context['enrollment']->id,
            'target_academic_session_id' => $context['targetSession']->id,
            'decision' => PromotionDecision::GRADUATED->value,
            'reason' => 'Completed Senior Secondary 3',
        ]
    );

    $response->assertCreated()
        ->assertJsonPath('data.decision', PromotionDecision::GRADUATED->value)
        ->assertJsonPath('data.target_enrollment', null);

    expect(Enrollment::query()->count())->toBe(1);
});

it('sets the student\'s own status to GRADUATED', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $context = promotionContext();
    $student = $context['enrollment']->student;

    withToken($token)->postJson(
        "/api/v1/students/{$student->id}/promote",
        [
            'source_enrollment_id' => $context['enrollment']->id,
            'target_academic_session_id' => $context['targetSession']->id,
            'decision' => PromotionDecision::GRADUATED->value,
        ]
    )->assertCreated();

    expect($student->refresh()->status)->toBe(StudentStatus::GRADUATED);
});

it('preserves the final academic history intact after graduation - the source enrollment remains queryable', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $context = promotionContext();

    withToken($token)->postJson(
        "/api/v1/students/{$context['enrollment']->student_id}/promote",
        [
            'source_enrollment_id' => $context['enrollment']->id,
            'target_academic_session_id' => $context['targetSession']->id,
            'decision' => PromotionDecision::GRADUATED->value,
        ]
    )->assertCreated();

    withToken($token)->getJson("/api/v1/enrollments/{$context['enrollment']->id}")
        ->assertOk()
        ->assertJsonPath('data.status', EnrollmentStatus::ACTIVE->value)
        ->assertJsonPath('data.school_class.id', $context['sourceClass']->id);
});

it('refuses a further promotion decision once a student has already graduated', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $context = promotionContext();

    withToken($token)->postJson(
        "/api/v1/students/{$context['enrollment']->student_id}/promote",
        [
            'source_enrollment_id' => $context['enrollment']->id,
            'target_academic_session_id' => $context['targetSession']->id,
            'decision' => PromotionDecision::GRADUATED->value,
        ]
    )->assertCreated();

    $laterSession = promotionSession('2027-09-01', '2028-07-31');

    withToken($token)->postJson(
        "/api/v1/students/{$context['enrollment']->student_id}/promote",
        [
            'source_enrollment_id' => $context['enrollment']->id,
            'target_academic_session_id' => $laterSession->id,
            'decision' => PromotionDecision::GRADUATED->value,
        ]
    )->assertStatus(422);

    expect(Promotion::query()->count())->toBe(1);
});

/*
| NOT_ELIGIBLE
*/

it('records a NOT_ELIGIBLE decision with no target enrollment and no change to the student\'s status', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $context = promotionContext();
    $student = $context['enrollment']->student;

    $response = withToken($token)->postJson(
        "/api/v1/students/{$student->id}/promote",
        [
            'source_enrollment_id' => $context['enrollment']->id,
            'target_academic_session_id' => $context['targetSession']->id,
            'decision' => PromotionDecision::NOT_ELIGIBLE->value,
            'reason' => 'Outstanding disciplinary review',
        ]
    );

    $response->assertCreated()->assertJsonPath('data.target_enrollment', null);

    expect($student->refresh()->status->value)->toBe('ACTIVE')
        ->and(Enrollment::query()->count())->toBe(1);
});

it('allows a later decision for the same student after a NOT_ELIGIBLE record, once a different target session is named', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $context = promotionContext();

    withToken($token)->postJson(
        "/api/v1/students/{$context['enrollment']->student_id}/promote",
        [
            'source_enrollment_id' => $context['enrollment']->id,
            'target_academic_session_id' => $context['targetSession']->id,
            'decision' => PromotionDecision::NOT_ELIGIBLE->value,
        ]
    )->assertCreated();

    $laterSession = promotionSession('2027-09-01', '2028-07-31');

    withToken($token)->postJson(
        "/api/v1/students/{$context['enrollment']->student_id}/promote",
        promotePayload($context, ['target_academic_session_id' => $laterSession->id])
    )->assertCreated();

    expect(Promotion::query()->count())->toBe(2);
});

/*
| Validation
*/

it('rejects a source enrollment that does not exist', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $context = promotionContext();

    withToken($token)->postJson(
        "/api/v1/students/{$context['enrollment']->student_id}/promote",
        promotePayload($context, ['source_enrollment_id' => 999999])
    )->assertUnprocessable()->assertJsonValidationErrors('source_enrollment_id');
});

it('rejects a source enrollment that belongs to a different student', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $context = promotionContext();
    $otherStudent = pupil();

    withToken($token)->postJson(
        "/api/v1/students/{$otherStudent->id}/promote",
        promotePayload($context)
    )->assertUnprocessable()->assertJsonValidationErrors('source_enrollment_id');

    expect(Promotion::query()->count())->toBe(0);
});

it('rejects a source enrollment that has already been withdrawn', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $context = promotionContext();
    $context['enrollment']->forceFill(['status' => EnrollmentStatus::WITHDRAWN])->save();

    withToken($token)->postJson(
        "/api/v1/students/{$context['enrollment']->student_id}/promote",
        promotePayload($context)
    )->assertUnprocessable()->assertJsonValidationErrors('source_enrollment_id');
});

it('rejects a target academic session that does not exist', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $context = promotionContext();

    withToken($token)->postJson(
        "/api/v1/students/{$context['enrollment']->student_id}/promote",
        promotePayload($context, ['target_academic_session_id' => 999999])
    )->assertUnprocessable()->assertJsonValidationErrors('target_academic_session_id');
});

it('rejects a target academic session that is COMPLETED', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $context = promotionContext();
    $context['targetSession']->forceFill(['status' => AcademicSessionStatus::COMPLETED])->save();

    withToken($token)->postJson(
        "/api/v1/students/{$context['enrollment']->student_id}/promote",
        promotePayload($context)
    )->assertUnprocessable()->assertJsonValidationErrors('target_academic_session_id');
});

it('rejects the same session as both source and target', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $context = promotionContext();

    withToken($token)->postJson(
        "/api/v1/students/{$context['enrollment']->student_id}/promote",
        [
            'source_enrollment_id' => $context['enrollment']->id,
            'target_academic_session_id' => $context['sourceSession']->id,
            'decision' => PromotionDecision::GRADUATED->value,
        ]
    )->assertStatus(422);

    expect(Promotion::query()->count())->toBe(0);
});

it('rejects a target academic session that starts before the source enrollment\'s own session', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $context = promotionContext();
    $earlierSession = promotionSession('2020-09-01', '2021-07-31');

    withToken($token)->postJson(
        "/api/v1/students/{$context['enrollment']->student_id}/promote",
        [
            'source_enrollment_id' => $context['enrollment']->id,
            'target_academic_session_id' => $earlierSession->id,
            'decision' => PromotionDecision::GRADUATED->value,
        ]
    )->assertStatus(422);

    expect(Promotion::query()->count())->toBe(0);
});

it('rejects a target class that does not exist or is not active', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $context = promotionContext();

    withToken($token)->postJson(
        "/api/v1/students/{$context['enrollment']->student_id}/promote",
        promotePayload($context, ['target_school_class_id' => 999999])
    )->assertUnprocessable()->assertJsonValidationErrors('target_school_class_id');
});

it('rejects a target section that belongs to a different class than the target class', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $context = promotionContext();

    // sourceSection belongs to sourceClass, not targetClass.
    withToken($token)->postJson(
        "/api/v1/students/{$context['enrollment']->student_id}/promote",
        promotePayload($context, ['target_section_id' => $context['sourceSection']->id])
    )->assertUnprocessable()->assertJsonValidationErrors('target_section_id');

    expect(Promotion::query()->count())->toBe(0);
});

it('requires a target class and section for a PROMOTED decision', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $context = promotionContext();

    withToken($token)->postJson(
        "/api/v1/students/{$context['enrollment']->student_id}/promote",
        [
            'source_enrollment_id' => $context['enrollment']->id,
            'target_academic_session_id' => $context['targetSession']->id,
            'decision' => PromotionDecision::PROMOTED->value,
        ]
    )->assertUnprocessable()->assertJsonValidationErrors(['target_school_class_id', 'target_section_id']);
});

it('rejects a target class/section for a GRADUATED decision', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $context = promotionContext();

    withToken($token)->postJson(
        "/api/v1/students/{$context['enrollment']->student_id}/promote",
        [
            'source_enrollment_id' => $context['enrollment']->id,
            'target_academic_session_id' => $context['targetSession']->id,
            'decision' => PromotionDecision::GRADUATED->value,
            'target_school_class_id' => $context['targetClass']->id,
            'target_section_id' => $context['targetSection']->id,
        ]
    )->assertUnprocessable()->assertJsonValidationErrors(['target_school_class_id', 'target_section_id']);
});

it('rejects a duplicate target enrollment - the target academic session already has one for this student', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $context = promotionContext();

    // A second, unrelated enrollment already exists for the SAME student and target session.
    Enrollment::factory()->create([
        'student_id' => $context['enrollment']->student_id,
        'academic_session_id' => $context['targetSession']->id,
        'school_class_id' => $context['targetClass']->id,
        'section_id' => $context['targetSection']->id,
    ]);

    withToken($token)->postJson(
        "/api/v1/students/{$context['enrollment']->student_id}/promote",
        promotePayload($context)
    )->assertStatus(422);

    expect(Promotion::query()->count())->toBe(0)
        ->and(Enrollment::query()->count())->toBe(2);
});

it('rejects an invalid decision value', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $context = promotionContext();

    withToken($token)->postJson(
        "/api/v1/students/{$context['enrollment']->student_id}/promote",
        promotePayload($context, ['decision' => 'SOMETHING_ELSE'])
    )->assertUnprocessable()->assertJsonValidationErrors('decision');
});

/*
| Result integration and historical safety
*/

it('does not modify results attached to the source enrollment', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $context = promotionContext();
    $admin = userWithRole(Role::ADMIN);

    $classSubject = activeClassSubject(['school_class_id' => $context['sourceClass']->id]);
    $assessment = activeAssessment([
        'class_subject_id' => $classSubject->id,
        'term_id' => Term::factory()->forSession($context['sourceSession'], 1)->create()->id,
        'max_score' => 20,
    ]);
    Score::factory()->forAssessment($assessment)->forEnrollment($context['enrollment'])->create(['score' => 15]);

    $result = app(ResultService::class)->compile([
        'enrollment_id' => $context['enrollment']->id,
        'class_subject_id' => $classSubject->id,
        'term_id' => $assessment->term_id,
    ], $admin);
    $originalPercentage = $result->percentage;

    withToken($token)->postJson(
        "/api/v1/students/{$context['enrollment']->student_id}/promote",
        promotePayload($context)
    )->assertCreated();

    expect((string) $result->refresh()->percentage)->toBe((string) $originalPercentage)
        ->and($result->enrollment_id)->toBe($context['enrollment']->id);
});

it('does not attach future results to the old enrollment - a result compiled after promotion for the target class uses the NEW enrollment', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $context = promotionContext();
    $admin = userWithRole(Role::ADMIN);

    $response = withToken($token)->postJson(
        "/api/v1/students/{$context['enrollment']->student_id}/promote",
        promotePayload($context)
    )->assertCreated();

    $targetEnrollmentId = $response->json('data.target_enrollment.id');

    $newClassSubject = activeClassSubject(['school_class_id' => $context['targetClass']->id]);
    $newTerm = Term::factory()->forSession($context['targetSession'], 1)->create();
    $newAssessment = activeAssessment(['class_subject_id' => $newClassSubject->id, 'term_id' => $newTerm->id, 'max_score' => 20]);
    Score::factory()->forAssessment($newAssessment)->create(['enrollment_id' => $targetEnrollmentId, 'score' => 18]);

    $newResult = app(ResultService::class)->compile([
        'enrollment_id' => $targetEnrollmentId,
        'class_subject_id' => $newClassSubject->id,
        'term_id' => $newTerm->id,
    ], $admin);

    expect($newResult->enrollment_id)->toBe($targetEnrollmentId)
        ->and($newResult->enrollment_id)->not->toBe($context['enrollment']->id);
});

/*
| Response structure
*/

it('lists promotions through the same envelope as every other list in the API', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    promotedStudent();

    withToken($token)->getJson('/api/v1/promotions')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [['id', 'source_enrollment', 'target_enrollment', 'target_academic_session', 'decision', 'reason', 'decided_by', 'decided_at']],
            'meta' => ['current_page', 'per_page', 'total', 'last_page'],
        ]);
});

it('shows a single promotion', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $promotion = promotedStudent();

    withToken($token)->getJson("/api/v1/promotions/{$promotion->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $promotion->id);
});

it('answers 404 for a promotion that does not exist', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    withToken($token)->getJson('/api/v1/promotions/999999')->assertNotFound();
});

it('filters promotions by decision', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $promoted = promotedStudent();

    $context = promotionContext();
    app(PromotionService::class)->promote($context['enrollment']->student, [
        'source_enrollment_id' => $context['enrollment']->id,
        'target_academic_session_id' => $context['targetSession']->id,
        'decision' => PromotionDecision::GRADUATED->value,
    ], userWithRole(Role::ADMIN));

    withToken($token)->getJson('/api/v1/promotions?decision=PROMOTED')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $promoted->id);
});

it('filters promotions by student', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $promotion = promotedStudent();
    promotedStudent();

    withToken($token)->getJson("/api/v1/promotions?student_id={$promotion->sourceEnrollment->student_id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $promotion->id);
});

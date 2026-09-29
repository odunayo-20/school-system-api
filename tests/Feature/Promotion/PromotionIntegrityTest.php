<?php

use App\Enums\PromotionDecision;
use App\Enums\Role;
use App\Exceptions\BusinessRuleViolation;
use App\Models\AcademicSession;
use App\Models\Enrollment;
use App\Models\Promotion;
use App\Models\User;
use App\Services\Promotion\PromotionService;
use Illuminate\Database\QueryException;

/*
|--------------------------------------------------------------------------
| Database integrity: the two-layer idempotency guarantee, transactional
| rollback and foreign-key deletion behaviour (Module 15)
|--------------------------------------------------------------------------
*/

/*
| Idempotency
*/

it('lets the database catch a duplicate (source_enrollment_id, target_academic_session_id) pair that the service never attempts to insert twice', function (): void {
    $context = promotionContext();

    Promotion::factory()
        ->forSourceEnrollment($context['enrollment'])
        ->forTargetSession($context['targetSession'])
        ->notEligible()
        ->create();

    expect(fn () => (new Promotion)->forceFill([
        'source_enrollment_id' => $context['enrollment']->id,
        'target_academic_session_id' => $context['targetSession']->id,
        'target_enrollment_id' => null,
        'decision' => PromotionDecision::NOT_ELIGIBLE,
        'decided_by' => User::factory()->admin()->create()->id,
        'decided_at' => now(),
    ])->save())->toThrow(QueryException::class);

    expect(Promotion::query()->where('source_enrollment_id', $context['enrollment']->id)->count())->toBe(1);
});

it('refuses a repeated PROMOTED call for the same source enrollment and target session at the service layer', function (): void {
    // The first call creates a target enrollment via EnrollmentService::create(); the second
    // hits the pre-existing enrollments.unique(student_id, academic_session_id) index and is
    // translated into the identical BusinessRuleViolation an unrelated duplicate enrollment
    // attempt already produces - the enrollments table is the idempotency backstop for any
    // decision that creates a placement.
    $admin = userWithRole(Role::ADMIN);
    $service = app(PromotionService::class);
    $context = promotionContext();

    $attributes = [
        'source_enrollment_id' => $context['enrollment']->id,
        'target_academic_session_id' => $context['targetSession']->id,
        'decision' => PromotionDecision::PROMOTED->value,
        'target_school_class_id' => $context['targetClass']->id,
        'target_section_id' => $context['targetSection']->id,
    ];

    $service->promote($context['enrollment']->student, $attributes, $admin);

    expect(fn () => $service->promote($context['enrollment']->student, $attributes, $admin))
        ->toThrow(BusinessRuleViolation::class);

    expect(Promotion::query()->count())->toBe(1)
        ->and(Enrollment::query()->count())->toBe(2);
});

it('refuses a repeated GRADUATED call for the same source enrollment and target session at the service layer', function (): void {
    // GRADUATED creates no enrollment, so the enrollments unique index cannot catch a repeat -
    // this is exactly what promotions.unique(source_enrollment_id, target_academic_session_id)
    // exists for.
    $admin = userWithRole(Role::ADMIN);
    $service = app(PromotionService::class);
    $context = promotionContext();

    $attributes = [
        'source_enrollment_id' => $context['enrollment']->id,
        'target_academic_session_id' => $context['targetSession']->id,
        'decision' => PromotionDecision::GRADUATED->value,
    ];

    $service->promote($context['enrollment']->student, $attributes, $admin);

    expect(fn () => $service->promote($context['enrollment']->student, $attributes, $admin))
        ->toThrow(
            BusinessRuleViolation::class,
            'This student has already left the school (GRADUATED), so no further promotion decision can be recorded.'
        );

    expect(Promotion::query()->count())->toBe(1);
});

it('is safe under three back-to-back attempts for the same NOT_ELIGIBLE pair - never more than one row', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $service = app(PromotionService::class);
    $context = promotionContext();

    $attributes = [
        'source_enrollment_id' => $context['enrollment']->id,
        'target_academic_session_id' => $context['targetSession']->id,
        'decision' => PromotionDecision::NOT_ELIGIBLE->value,
    ];

    $service->promote($context['enrollment']->student, $attributes, $admin);

    foreach (range(1, 2) as $attempt) {
        expect(fn () => $service->promote($context['enrollment']->student, $attributes, $admin))
            ->toThrow(BusinessRuleViolation::class);
    }

    expect(Promotion::query()->count())->toBe(1);
});

/*
| Transactional safety
*/

it('rolls back the student status change when the target enrollment insert fails inside the same transaction', function (): void {
    // A PROMOTED decision that also races a duplicate enrollment (created moments earlier for
    // the exact same student/target session pair, outside this call) must fail atomically - no
    // Promotion row, and (for a decision that would also touch the student's own status) no
    // partial student mutation either. GRADUATED never creates a target enrollment, so this
    // exercises the PROMOTED path, the one where createTargetEnrollment() can actually fail.
    $admin = userWithRole(Role::ADMIN);
    $service = app(PromotionService::class);
    $context = promotionContext();

    Enrollment::factory()->create([
        'student_id' => $context['enrollment']->student_id,
        'academic_session_id' => $context['targetSession']->id,
        'school_class_id' => $context['targetClass']->id,
        'section_id' => $context['targetSection']->id,
    ]);

    expect(fn () => $service->promote($context['enrollment']->student, [
        'source_enrollment_id' => $context['enrollment']->id,
        'target_academic_session_id' => $context['targetSession']->id,
        'decision' => PromotionDecision::PROMOTED->value,
        'target_school_class_id' => $context['targetClass']->id,
        'target_section_id' => $context['targetSection']->id,
    ], $admin))->toThrow(BusinessRuleViolation::class);

    expect(Promotion::query()->count())->toBe(0)
        // Exactly the one pre-existing enrollment plus the seeded conflicting one - no third.
        ->and(Enrollment::query()->count())->toBe(2)
        ->and($context['enrollment']->student->refresh()->status->value)->toBe('ACTIVE');
});

/*
| Foreign-key deletion behaviour
*/

it('refuses to delete a source enrollment that a promotion still references', function (): void {
    $promotion = promotedStudent();
    $sourceEnrollmentId = $promotion->source_enrollment_id;

    expect(fn () => Enrollment::query()->findOrFail($sourceEnrollmentId)->delete())->toThrow(QueryException::class);

    expect(Enrollment::query()->whereKey($sourceEnrollmentId)->exists())->toBeTrue()
        ->and(Promotion::query()->whereKey($promotion->id)->exists())->toBeTrue();
});

it('refuses to delete a target enrollment that a promotion still references', function (): void {
    $promotion = promotedStudent();
    $targetEnrollmentId = $promotion->target_enrollment_id;

    expect(fn () => Enrollment::query()->findOrFail($targetEnrollmentId)->delete())->toThrow(QueryException::class);

    expect(Enrollment::query()->whereKey($targetEnrollmentId)->exists())->toBeTrue()
        ->and(Promotion::query()->whereKey($promotion->id)->exists())->toBeTrue();
});

it('refuses to delete a target academic session that a promotion still references', function (): void {
    $promotion = promotedStudent();
    $targetSessionId = $promotion->target_academic_session_id;

    expect(fn () => AcademicSession::query()->findOrFail($targetSessionId)->delete())->toThrow(QueryException::class);

    expect(AcademicSession::query()->whereKey($targetSessionId)->exists())->toBeTrue()
        ->and(Promotion::query()->whereKey($promotion->id)->exists())->toBeTrue();
});

it('nulls decided_by rather than blocking deletion of the deciding user - the historical decision outlives the account', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $context = promotionContext();

    $promotion = app(PromotionService::class)->promote($context['enrollment']->student, [
        'source_enrollment_id' => $context['enrollment']->id,
        'target_academic_session_id' => $context['targetSession']->id,
        'decision' => PromotionDecision::PROMOTED->value,
        'target_school_class_id' => $context['targetClass']->id,
        'target_section_id' => $context['targetSection']->id,
    ], $admin);

    $admin->delete();

    expect($promotion->refresh()->decided_by)->toBeNull()
        ->and(Promotion::query()->whereKey($promotion->id)->exists())->toBeTrue();
});

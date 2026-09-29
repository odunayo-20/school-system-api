<?php

use App\Enums\Role;
use App\Exceptions\BusinessRuleViolation;
use App\Models\Assessment;
use App\Models\AssessmentType;
use App\Models\ClassSubject;
use App\Models\Term;
use App\Services\Assessment\AssessmentService;
use Illuminate\Database\QueryException;

/*
|--------------------------------------------------------------------------
| Database integrity: races and foreign-key deletion behaviour (Module 09)
|--------------------------------------------------------------------------
*/

it('lets the database catch a duplicate assessment name that validation could not, and reports it as a 422', function (): void {
    // Simulates the race two concurrent requests would create: both pass validation (neither
    // sees the other's row yet), and the second insert is the one that must fail cleanly. The
    // same technique SubjectIntegrityTest and TeacherAssignmentWorkflowTest both use for their
    // own unique-index races.
    $service = app(AssessmentService::class);
    $assessment = activeAssessment(['name' => 'CA 1']);

    expect(fn () => $service->create([
        'class_subject_id' => $assessment->class_subject_id,
        'term_id' => $assessment->term_id,
        'assessment_type_id' => $assessment->assessment_type_id,
        'name' => 'CA 1',
        'max_score' => 20,
    ]))->toThrow(BusinessRuleViolation::class, 'An assessment with this name already exists for this class subject and term.');

    expect(Assessment::query()->where('class_subject_id', $assessment->class_subject_id)->count())->toBe(1);
});

it('refuses to delete a class subject that an assessment still references', function (): void {
    // AssessmentService itself never deletes a class subject - this pins the FK's own
    // behaviour, reached through the class subject's own model, the identical technique
    // TeacherAssignmentIntegrityTest uses for the same foreign key.
    $assessment = activeAssessment();
    $classSubjectId = $assessment->class_subject_id;

    expect(fn () => $assessment->classSubject->delete())->toThrow(QueryException::class);

    expect(ClassSubject::query()->whereKey($classSubjectId)->exists())->toBeTrue()
        ->and(Assessment::query()->whereKey($assessment->id)->exists())->toBeTrue();
});

it('refuses to delete a term that an assessment still references', function (): void {
    $assessment = activeAssessment();
    $termId = $assessment->term_id;

    expect(fn () => $assessment->term->delete())->toThrow(QueryException::class);

    expect(Term::query()->whereKey($termId)->exists())->toBeTrue()
        ->and(Assessment::query()->whereKey($assessment->id)->exists())->toBeTrue();
});

it('refuses to delete an assessment type that an assessment still references, at the service layer directly', function (): void {
    // A second, non-HTTP confirmation that AssessmentService::deleteAssessmentType() is the
    // one place this rule lives, reachable by any caller and not only the guarded HTTP path -
    // the identical confirmation SubjectIntegrityTest gives deleteSubject().
    $service = app(AssessmentService::class);
    $assessment = activeAssessment();

    expect(fn () => $service->deleteAssessmentType($assessment->assessmentType))
        ->toThrow(BusinessRuleViolation::class);

    expect(AssessmentType::query()->whereKey($assessment->assessment_type_id)->exists())->toBeTrue();
});

it('refuses to delete an assessment type that an assessment still references, at the database layer directly', function (): void {
    // The restrictOnDelete foreign key is the backstop behind AssessmentService's own guard -
    // reached here by bypassing the service entirely, exactly as the class-subject and term
    // tests above do for their own foreign keys.
    $assessment = activeAssessment();
    $assessmentTypeId = $assessment->assessment_type_id;

    expect(fn () => $assessment->assessmentType->delete())->toThrow(QueryException::class);

    expect(AssessmentType::query()->whereKey($assessmentTypeId)->exists())->toBeTrue()
        ->and(Assessment::query()->whereKey($assessment->id)->exists())->toBeTrue();
});

it('refuses to delete a class that a class subject used by an assessment still references', function (): void {
    // AcademicStructureService::deleteClass() does not know about assessments any more than it
    // knows about class_subjects or teacher_assignments - the same documented, accruing gap
    // Module 07 and Module 08 both accepted rather than editing a stable earlier module. This
    // test pins the property that actually matters regardless of status code: NO CASCADE, EVER.
    $token = loginAs(userWithRole(Role::ADMIN));
    $assessment = activeAssessment();
    $classId = $assessment->classSubject->school_class_id;

    $response = withToken($token)->deleteJson("/api/v1/classes/{$classId}");

    $response->assertStatus(500);

    expect(Assessment::query()->whereKey($assessment->id)->exists())->toBeTrue();
});

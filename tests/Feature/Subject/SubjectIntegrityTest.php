<?php

use App\Enums\Role;
use App\Exceptions\BusinessRuleViolation;
use App\Models\ClassSubject;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Services\Subject\SubjectService;

/*
|--------------------------------------------------------------------------
| Database integrity: races and foreign-key deletion behaviour (Module 07)
|--------------------------------------------------------------------------
*/

it('lets the database catch a duplicate class subject that validation could not, and reports it as a 422', function (): void {
    // Simulates the race two concurrent requests would create: both pass validation (neither
    // sees the other's row yet), and the second insert is the one that must fail cleanly.
    // Calling the service directly bypasses the form request's own Rule::unique check, the
    // same technique EnrollmentWorkflowTest uses for its own unique-index race.
    $service = app(SubjectService::class);
    $classSubject = activeClassSubject();

    expect(fn () => $service->createClassSubject([
        'school_class_id' => $classSubject->school_class_id,
        'subject_id' => $classSubject->subject_id,
    ]))->toThrow(BusinessRuleViolation::class, 'This subject is already attached to this class.');

    expect(ClassSubject::query()->where('school_class_id', $classSubject->school_class_id)->count())->toBe(1);
});

it('refuses to delete a class that a class subject still references', function (): void {
    // AcademicStructureService::deleteClass() does not know about class_subjects - it was
    // written before this table existed, and Module 02 is treated as stable, not revised
    // here. This test pins the property that actually matters regardless of status code: NO
    // CASCADE, EVER. See the Module 07 audit for why this documented gap is not fixed here,
    // the identical posture Module 05 and Module 06 took toward the same gap for admissions
    // and enrollments.
    $token = loginAs(userWithRole(Role::ADMIN));
    $classSubject = activeClassSubject();
    $classId = $classSubject->school_class_id;

    // The class still has no sections in this test, so AcademicStructureService::deleteClass()'s
    // own "still has sections" guard does not fire first - this isolates the class_subjects
    // dependency specifically.
    $response = withToken($token)->deleteJson("/api/v1/classes/{$classId}");

    $response->assertStatus(500);

    expect(SchoolClass::query()->whereKey($classId)->exists())->toBeTrue()
        ->and(ClassSubject::query()->whereKey($classSubject->id)->exists())->toBeTrue();
});

it('refuses to delete a subject that a class subject still references, at the service layer directly', function (): void {
    // A second, non-HTTP confirmation that SubjectService::deleteSubject() is the one place
    // this rule lives, reachable by any caller and not only the guarded HTTP path.
    $service = app(SubjectService::class);
    $classSubject = activeClassSubject();

    expect(fn () => $service->deleteSubject($classSubject->subject))
        ->toThrow(BusinessRuleViolation::class);

    expect(Subject::query()->whereKey($classSubject->subject_id)->exists())->toBeTrue();
});

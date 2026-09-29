<?php

use App\Enums\Role;
use App\Models\ClassSubject;
use App\Models\Staff;
use App\Models\TeacherAssignment;
use Illuminate\Database\QueryException;

/*
|--------------------------------------------------------------------------
| Database integrity: foreign-key deletion behaviour (Module 08)
|--------------------------------------------------------------------------
|
| Neither AcademicStructureService (classes/sections) nor SubjectService (class_subjects) nor
| any Staff deletion path (staff has no delete endpoint at all) knows about
| teacher_assignments - this table was written after all three. This test pins the property
| that actually matters regardless of status code: NO CASCADE, EVER, the same posture Module
| 05, 06 and 07 already verified for their own anchor records. See the Module 08 audit for why
| this documented gap is not fixed here.
*/

it('refuses to delete a class subject that a teacher assignment still references', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $assignment = activeAssignment();
    $classSubjectId = $assignment->class_subject_id;

    // class_subjects has no delete endpoint at all (Module 07's own deliberate decision), so
    // this reaches the database constraint through the service directly rather than HTTP -
    // the property under test is the restrictOnDelete foreign key itself.
    expect(fn () => $assignment->classSubject->delete())->toThrow(QueryException::class);

    expect(ClassSubject::query()->whereKey($classSubjectId)->exists())->toBeTrue()
        ->and(TeacherAssignment::query()->whereKey($assignment->id)->exists())->toBeTrue();
});

it('refuses to delete a staff member that a teacher assignment still references', function (): void {
    $assignment = activeAssignment();
    $staffId = $assignment->teaching_staff_id;

    // Staff has no delete endpoint in this project at all (Module 03's own decision), so this
    // exercises the constraint directly at the model layer - the same defence-in-depth
    // verification SubjectIntegrityTest performs for deleteSubject().
    expect(fn () => $assignment->teachingStaff->delete())->toThrow(QueryException::class);

    expect(Staff::query()->whereKey($staffId)->exists())->toBeTrue()
        ->and(TeacherAssignment::query()->whereKey($assignment->id)->exists())->toBeTrue();
});

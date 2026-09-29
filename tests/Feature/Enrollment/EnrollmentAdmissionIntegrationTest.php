<?php

use App\Enums\AdmissionStatus;
use App\Enums\Role;
use App\Enums\StudentStatus;
use App\Models\Admission;
use App\Models\Enrollment;
use App\Models\Student;

/*
|--------------------------------------------------------------------------
| Enrollment and Admission: the boundary between Module 05 and Module 06
|--------------------------------------------------------------------------
|
| Module 06's own audit decision: Admission is NOT a hard prerequisite for Enrollment. A
| Student created directly through Module 04 (no admission at all) and a Student created by
| Module 05's admit() are equally ordinary students once they exist, and Enrollment's only
| eligibility question is the one it owns - StudentStatus::ACTIVE - not "did this person have
| an Admission record". These tests pin that boundary from both directions.
*/

it('lets a student admitted through the admission workflow be enrolled', function (): void {
    $registrar = userWithRole(Role::REGISTRAR);
    $token = loginAs($registrar);

    $admission = pendingAdmission();

    $admitted = withToken($token)->postJson("/api/v1/admissions/{$admission->id}/admit");
    $admitted->assertOk();

    $studentId = $admitted->json('data.student.id');
    $section = activeSection();
    $session = eligibleSession();

    withToken($token)->postJson('/api/v1/enrollments', enrollmentCreatePayload([
        'student_id' => $studentId,
        'academic_session_id' => $session->id,
        'school_class_id' => $section->school_class_id,
        'section_id' => $section->id,
        'enrollment_date' => $session->start_date->toDateString(),
    ]))->assertCreated()->assertJsonPath('data.student.id', $studentId);

    expect(Enrollment::query()->where('student_id', $studentId)->count())->toBe(1);
});

it('lets a student created directly on the roll, with no admission record at all, be enrolled', function (): void {
    // The deliberate case: Module 04's POST /students remains a fully independent path to a
    // Student, and this module must not silently require an Admission that was never asked
    // for. See the Module 06 audit.
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $student = eligibleStudent();

    expect(Admission::query()->where('student_id', $student->id)->exists())->toBeFalse();

    withToken($token)->postJson('/api/v1/enrollments', enrollmentCreatePayload(['student_id' => $student->id]))
        ->assertCreated();
});

it('refuses to enroll a student whose admission was rejected, because no student was ever created for it', function (): void {
    // A rejected or withdrawn admission never reaches admit(), so it never creates a Student
    // at all - there is nothing whose id could even be sent to POST /enrollments. This test
    // pins that a rejected applicant simply does not exist as a Student, rather than existing
    // in some enrollable-but-ineligible state.
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $admission = decidedAdmission(AdmissionStatus::REJECTED);

    expect($admission->student_id)->toBeNull();

    withToken($token)->postJson('/api/v1/enrollments', enrollmentCreatePayload(['student_id' => 999999]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('student_id');
});

it('refuses to enroll a withdrawn student even though they were once validly admitted', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $admission = pendingAdmission();

    $admitted = withToken($token)->postJson("/api/v1/admissions/{$admission->id}/admit")->assertOk();
    $student = Student::query()->findOrFail($admitted->json('data.student.id'));

    // Withdraw the pupil from the roll through Module 04's own endpoint - the eligibility
    // question this module asks is about the STUDENT's current status, not the historical
    // fact that an admission once admitted them.
    withToken($token)->putJson("/api/v1/students/{$student->id}", [
        'first_name' => $student->first_name,
        'last_name' => $student->last_name,
        'status' => StudentStatus::WITHDRAWN->value,
    ])->assertOk();

    withToken($token)->postJson('/api/v1/enrollments', enrollmentCreatePayload(['student_id' => $student->id]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('student_id');
});

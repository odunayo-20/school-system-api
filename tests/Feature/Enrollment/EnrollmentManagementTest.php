<?php

use App\Enums\CatalogStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\Role;
use App\Enums\StudentStatus;
use App\Models\AcademicSession;
use App\Models\ClassLevel;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Student;
use Database\Seeders\EnrollmentPermissionSeeder;

/*
|--------------------------------------------------------------------------
| Enrollment management: create, read, amend (Module 06)
|--------------------------------------------------------------------------
*/

it('places a student in a class and section for a session', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $section = activeSection();
    $session = eligibleSession();
    $student = eligibleStudent();

    $response = withToken($token)->postJson('/api/v1/enrollments', enrollmentCreatePayload([
        'student_id' => $student->id,
        'academic_session_id' => $session->id,
        'school_class_id' => $section->school_class_id,
        'section_id' => $section->id,
        'enrollment_date' => $session->start_date->toDateString(),
    ]));

    $response->assertCreated()
        ->assertJsonPath('data.status', EnrollmentStatus::ACTIVE->value)
        ->assertJsonPath('data.student.id', $student->id)
        ->assertJsonPath('data.academic_session.id', $session->id)
        ->assertJsonPath('data.school_class.id', $section->school_class_id)
        ->assertJsonPath('data.section.id', $section->id);

    expect(Enrollment::query()->count())->toBe(1);
});

it('requires a student', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    $payload = enrollmentCreatePayload();
    unset($payload['student_id']);

    withToken($token)->postJson('/api/v1/enrollments', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('student_id');

    expect(Enrollment::query()->count())->toBe(0);
});

it('requires an academic session', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    $payload = enrollmentCreatePayload();
    unset($payload['academic_session_id']);

    withToken($token)->postJson('/api/v1/enrollments', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('academic_session_id');
});

it('requires a class', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    $payload = enrollmentCreatePayload();
    unset($payload['school_class_id']);

    withToken($token)->postJson('/api/v1/enrollments', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('school_class_id');
});

it('requires a section', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    $payload = enrollmentCreatePayload();
    unset($payload['section_id']);

    withToken($token)->postJson('/api/v1/enrollments', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('section_id');
});

it('refuses a student who does not exist', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    withToken($token)->postJson('/api/v1/enrollments', enrollmentCreatePayload(['student_id' => 999999]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('student_id');
});

it('refuses a student who is not active', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    foreach ([StudentStatus::INACTIVE, StudentStatus::GRADUATED, StudentStatus::WITHDRAWN] as $status) {
        $student = eligibleStudent(['status' => $status]);

        withToken($token)->postJson('/api/v1/enrollments', enrollmentCreatePayload(['student_id' => $student->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('student_id');
    }

    expect(Enrollment::query()->count())->toBe(0);
});

it('refuses a class that does not exist', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    withToken($token)->postJson('/api/v1/enrollments', enrollmentCreatePayload(['school_class_id' => 999999]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('school_class_id');
});

it('refuses a class that has been retired', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $retiredClass = SchoolClass::factory()->status(CatalogStatus::ARCHIVED)->create();
    $section = Section::factory()->within($retiredClass, 'A', 'A')->create();

    withToken($token)->postJson('/api/v1/enrollments', enrollmentCreatePayload([
        'school_class_id' => $retiredClass->id,
        'section_id' => $section->id,
    ]))->assertUnprocessable()->assertJsonValidationErrors('school_class_id');
});

it('refuses a class whose class level has been retired', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $retiredLevel = ClassLevel::factory()->status(CatalogStatus::ARCHIVED)->create();
    $class = SchoolClass::factory()->within($retiredLevel, 'JSS 1', 'JSS1')->create();
    $section = Section::factory()->within($class, 'A', 'A')->create();

    // The class itself is ACTIVE, so this passes the form request's own check, and is
    // refused by EnrollmentService::assertClassLevelActive() instead - the check that needs
    // the loaded class_level relation.
    withToken($token)->postJson('/api/v1/enrollments', enrollmentCreatePayload([
        'school_class_id' => $class->id,
        'section_id' => $section->id,
    ]))->assertStatus(422);

    expect(Enrollment::query()->count())->toBe(0);
});

it('refuses a section that does not exist', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    withToken($token)->postJson('/api/v1/enrollments', enrollmentCreatePayload(['section_id' => 999999]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('section_id');
});

it('refuses a section belonging to a different class', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $classA = SchoolClass::factory()->create();
    $classB = SchoolClass::factory()->create();
    $sectionOfB = Section::factory()->within($classB, 'A', 'A')->create();

    withToken($token)->postJson('/api/v1/enrollments', enrollmentCreatePayload([
        'school_class_id' => $classA->id,
        'section_id' => $sectionOfB->id,
    ]))->assertUnprocessable()->assertJsonValidationErrors('section_id');

    expect(Enrollment::query()->count())->toBe(0);
});

it('refuses a retired section', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $class = SchoolClass::factory()->create();
    $retiredSection = Section::factory()->within($class, 'A', 'A')->status(CatalogStatus::ARCHIVED)->create();

    withToken($token)->postJson('/api/v1/enrollments', enrollmentCreatePayload([
        'school_class_id' => $class->id,
        'section_id' => $retiredSection->id,
    ]))->assertUnprocessable()->assertJsonValidationErrors('section_id');
});

it('refuses an academic session that does not exist', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    withToken($token)->postJson('/api/v1/enrollments', enrollmentCreatePayload(['academic_session_id' => 999999]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('academic_session_id');
});

it('refuses an academic session that has already completed', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $completed = AcademicSession::factory()->completed()->create();

    withToken($token)->postJson('/api/v1/enrollments', enrollmentCreatePayload([
        'academic_session_id' => $completed->id,
        'enrollment_date' => $completed->start_date->toDateString(),
    ]))->assertUnprocessable()->assertJsonValidationErrors('academic_session_id');

    expect(Enrollment::query()->count())->toBe(0);
});

it('accepts an enrollment targeting the active session or an upcoming one', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    $active = AcademicSession::factory()->active()->create();
    $upcoming = AcademicSession::factory()->create();

    withToken($token)->postJson('/api/v1/enrollments', enrollmentCreatePayload([
        'academic_session_id' => $active->id,
        'enrollment_date' => $active->start_date->toDateString(),
    ]))->assertCreated();

    withToken($token)->postJson('/api/v1/enrollments', enrollmentCreatePayload([
        'academic_session_id' => $upcoming->id,
        'enrollment_date' => $upcoming->start_date->toDateString(),
    ]))->assertCreated();
});

it('refuses an enrollment date outside the session calendar', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $session = eligibleSession();

    withToken($token)->postJson('/api/v1/enrollments', enrollmentCreatePayload([
        'academic_session_id' => $session->id,
        'enrollment_date' => $session->end_date->copy()->addDay()->toDateString(),
    ]))->assertStatus(422)->assertJsonValidationErrors('enrollment_date');

    expect(Enrollment::query()->count())->toBe(0);
});

it('refuses a duplicate enrollment for the same student and session', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $student = eligibleStudent();
    $session = eligibleSession();

    withToken($token)->postJson('/api/v1/enrollments', enrollmentCreatePayload([
        'student_id' => $student->id,
        'academic_session_id' => $session->id,
        'enrollment_date' => $session->start_date->toDateString(),
    ]))->assertCreated();

    // A different class/section in the SAME session is still refused: the rule is keyed on
    // (student, session), not (student, session, class).
    $otherSection = activeSection();

    withToken($token)->postJson('/api/v1/enrollments', enrollmentCreatePayload([
        'student_id' => $student->id,
        'academic_session_id' => $session->id,
        'school_class_id' => $otherSection->school_class_id,
        'section_id' => $otherSection->id,
        'enrollment_date' => $session->start_date->toDateString(),
    ]))->assertUnprocessable()->assertJsonValidationErrors('student_id');

    expect(Enrollment::query()->where('student_id', $student->id)->count())->toBe(1);
});

it('allows the same student to be enrolled in two different sessions', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $student = eligibleStudent();

    $first = eligibleSession();
    $second = eligibleSession();

    withToken($token)->postJson('/api/v1/enrollments', enrollmentCreatePayload([
        'student_id' => $student->id,
        'academic_session_id' => $first->id,
        'enrollment_date' => $first->start_date->toDateString(),
    ]))->assertCreated();

    withToken($token)->postJson('/api/v1/enrollments', enrollmentCreatePayload([
        'student_id' => $student->id,
        'academic_session_id' => $second->id,
        'enrollment_date' => $second->start_date->toDateString(),
    ]))->assertCreated();

    expect(Enrollment::query()->where('student_id', $student->id)->count())->toBe(2);
});

it('has no field through which a create request can set the status', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    $response = withToken($token)->postJson('/api/v1/enrollments', enrollmentCreatePayload(['status' => 'WITHDRAWN']));

    $response->assertCreated()->assertJsonPath('data.status', EnrollmentStatus::ACTIVE->value);

    expect(Enrollment::query()->sole()->status)->toBe(EnrollmentStatus::ACTIVE);
});

it('shows a single enrollment', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $enrollment = activeEnrollment();

    withToken($token)->getJson("/api/v1/enrollments/{$enrollment->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $enrollment->id);
});

it('answers 404 for an enrollment that does not exist', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    withToken($token)->getJson('/api/v1/enrollments/999999')->assertNotFound();
});

it('lists enrollments newest first', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    $first = activeEnrollment();
    $second = activeEnrollment();

    $response = withToken($token)->getJson('/api/v1/enrollments');

    $response->assertOk();

    expect(array_column($response->json('data'), 'id'))->toBe([$second->id, $first->id]);
});

it('amends the enrollment date and notes of an active enrollment', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $enrollment = activeEnrollment();

    withToken($token)->putJson("/api/v1/enrollments/{$enrollment->id}", enrollmentUpdatePayload([
        'enrollment_date' => $enrollment->academicSession->start_date->toDateString(),
        'notes' => 'Corrected the entry date.',
    ]))->assertOk()->assertJsonPath('data.notes', 'Corrected the entry date.');

    expect($enrollment->refresh()->notes)->toBe('Corrected the entry date.');
});

it('requires the enrollment date on amend', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $enrollment = activeEnrollment();

    withToken($token)->putJson("/api/v1/enrollments/{$enrollment->id}", ['notes' => 'x'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('enrollment_date');
});

it('refuses an amended enrollment date outside the session calendar', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $enrollment = activeEnrollment();

    withToken($token)->putJson("/api/v1/enrollments/{$enrollment->id}", enrollmentUpdatePayload([
        'enrollment_date' => $enrollment->academicSession->end_date->copy()->addDay()->toDateString(),
    ]))->assertStatus(422)->assertJsonValidationErrors('enrollment_date');
});

it('cannot reach student_id, academic_session_id, school_class_id or section_id through the amend endpoint', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $enrollment = activeEnrollment();
    $otherStudent = Student::factory()->create();
    $otherSession = AcademicSession::factory()->create();
    $otherSection = activeSection();

    withToken($token)->putJson("/api/v1/enrollments/{$enrollment->id}", enrollmentUpdatePayload([
        'enrollment_date' => $enrollment->academicSession->start_date->toDateString(),
        'student_id' => $otherStudent->id,
        'academic_session_id' => $otherSession->id,
        'school_class_id' => $otherSection->school_class_id,
        'section_id' => $otherSection->id,
        'status' => 'WITHDRAWN',
    ]))->assertOk();

    $enrollment->refresh();

    expect($enrollment->student_id)->not->toBe($otherStudent->id)
        ->and($enrollment->academic_session_id)->not->toBe($otherSession->id)
        ->and($enrollment->section_id)->not->toBe($otherSection->id)
        ->and($enrollment->status)->toBe(EnrollmentStatus::ACTIVE);
});

it('refuses to amend a terminal enrollment', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $enrollment = decidedEnrollment(EnrollmentStatus::WITHDRAWN);

    withToken($token)->putJson("/api/v1/enrollments/{$enrollment->id}", enrollmentUpdatePayload([
        'enrollment_date' => $enrollment->academicSession->start_date->toDateString(),
        'notes' => 'Changed',
    ]))->assertStatus(422);

    expect($enrollment->refresh()->notes)->not->toBe('Changed');
});

it('answers 405 for a PATCH', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $enrollment = activeEnrollment();

    withToken($token)->patchJson("/api/v1/enrollments/{$enrollment->id}", enrollmentUpdatePayload())
        ->assertStatus(405)
        ->assertHeader('Allow');
});

it('refuses a delete outright, because an enrollment is academic history', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $enrollment = activeEnrollment();

    withToken($token)->deleteJson("/api/v1/enrollments/{$enrollment->id}")->assertStatus(405);

    expect(Enrollment::query()->whereKey($enrollment->id)->exists())->toBeTrue();
});

it('has no delete permission to grant in the first place', function (): void {
    expect(EnrollmentPermissionSeeder::names())
        ->toContain('enrollments.view', 'enrollments.create', 'enrollments.update', 'enrollments.withdraw', 'enrollments.cancel')
        ->not->toContain('enrollments.delete');
});

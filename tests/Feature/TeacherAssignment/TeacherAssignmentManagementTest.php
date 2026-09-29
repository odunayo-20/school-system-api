<?php

use App\Enums\CatalogStatus;
use App\Enums\EmploymentStatus;
use App\Enums\Role;
use App\Enums\StaffType;
use App\Enums\TeacherAssignmentStatus;
use App\Models\AcademicSession;
use App\Models\ClassLevel;
use App\Models\ClassSubject;
use App\Models\SchoolClass;
use App\Models\TeacherAssignment;
use Database\Seeders\TeacherAssignmentPermissionSeeder;

/*
|--------------------------------------------------------------------------
| Teacher assignment: create, read, amend (Module 08)
|--------------------------------------------------------------------------
*/

it('assigns a teacher to a class subject for a session', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $teacher = eligibleTeacher();
    $classSubject = activeClassSubject();
    $session = eligibleSession();

    $response = withToken($token)->postJson('/api/v1/teacher-assignments', assignmentCreatePayload([
        'teaching_staff_id' => $teacher->id,
        'class_subject_id' => $classSubject->id,
        'academic_session_id' => $session->id,
    ]));

    $response->assertCreated()
        ->assertJsonPath('data.status', TeacherAssignmentStatus::ACTIVE->value)
        ->assertJsonPath('data.teaching_staff.id', $teacher->id)
        ->assertJsonPath('data.class_subject.id', $classSubject->id)
        ->assertJsonPath('data.academic_session.id', $session->id);

    expect(TeacherAssignment::query()->count())->toBe(1);
});

it('requires a teaching staff member', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    $payload = assignmentCreatePayload();
    unset($payload['teaching_staff_id']);

    withToken($token)->postJson('/api/v1/teacher-assignments', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('teaching_staff_id');
});

it('requires a class subject', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    $payload = assignmentCreatePayload();
    unset($payload['class_subject_id']);

    withToken($token)->postJson('/api/v1/teacher-assignments', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('class_subject_id');
});

it('requires an academic session', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    $payload = assignmentCreatePayload();
    unset($payload['academic_session_id']);

    withToken($token)->postJson('/api/v1/teacher-assignments', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('academic_session_id');
});

it('refuses a staff member who does not exist', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    withToken($token)->postJson('/api/v1/teacher-assignments', assignmentCreatePayload(['teaching_staff_id' => 999999]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('teaching_staff_id');
});

it('refuses a non-teaching staff member', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $nonTeaching = staffMember(StaffType::NON_TEACHING);

    withToken($token)->postJson('/api/v1/teacher-assignments', assignmentCreatePayload(['teaching_staff_id' => $nonTeaching->id]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('teaching_staff_id');

    expect(TeacherAssignment::query()->count())->toBe(0);
});

it('refuses a terminated teaching staff member', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $terminated = staffMember(StaffType::TEACHING, ['status' => EmploymentStatus::TERMINATED]);

    withToken($token)->postJson('/api/v1/teacher-assignments', assignmentCreatePayload(['teaching_staff_id' => $terminated->id]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('teaching_staff_id');
});

it('refuses an inactive teaching staff member', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $inactive = staffMember(StaffType::TEACHING, ['status' => EmploymentStatus::INACTIVE]);

    withToken($token)->postJson('/api/v1/teacher-assignments', assignmentCreatePayload(['teaching_staff_id' => $inactive->id]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('teaching_staff_id');
});

it('refuses a class subject that does not exist', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    withToken($token)->postJson('/api/v1/teacher-assignments', assignmentCreatePayload(['class_subject_id' => 999999]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('class_subject_id');
});

it('refuses a class subject that has been deactivated', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $inactiveOffering = activeClassSubject(['status' => CatalogStatus::INACTIVE]);

    withToken($token)->postJson('/api/v1/teacher-assignments', assignmentCreatePayload(['class_subject_id' => $inactiveOffering->id]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('class_subject_id');
});

it('refuses a class subject whose class has been retired', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $retiredClass = SchoolClass::factory()->status(CatalogStatus::ARCHIVED)->create();
    $classSubject = ClassSubject::factory()->forClass($retiredClass)->create();

    // The class subject's own status is still ACTIVE (Module 07: the two statuses are
    // independent), so this passes the form request's own check, and is refused by
    // TeacherAssignmentService::assertClassSubjectSelectable() instead.
    withToken($token)->postJson('/api/v1/teacher-assignments', assignmentCreatePayload([
        'class_subject_id' => $classSubject->id,
    ]))->assertStatus(422);

    expect(TeacherAssignment::query()->count())->toBe(0);
});

it('refuses a class subject whose class level has been retired', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $retiredLevel = ClassLevel::factory()->status(CatalogStatus::ARCHIVED)->create();
    $class = SchoolClass::factory()->within($retiredLevel, 'JSS 1', 'JSS1')->create();
    $classSubject = ClassSubject::factory()->forClass($class)->create();

    withToken($token)->postJson('/api/v1/teacher-assignments', assignmentCreatePayload([
        'class_subject_id' => $classSubject->id,
    ]))->assertStatus(422);

    expect(TeacherAssignment::query()->count())->toBe(0);
});

it('refuses an academic session that does not exist', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    withToken($token)->postJson('/api/v1/teacher-assignments', assignmentCreatePayload(['academic_session_id' => 999999]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('academic_session_id');
});

it('refuses an academic session that has already completed', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $completed = AcademicSession::factory()->completed()->create();

    withToken($token)->postJson('/api/v1/teacher-assignments', assignmentCreatePayload([
        'academic_session_id' => $completed->id,
    ]))->assertUnprocessable()->assertJsonValidationErrors('academic_session_id');
});

it('accepts an assignment targeting the active session or an upcoming one', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    $active = AcademicSession::factory()->active()->create();
    $upcoming = AcademicSession::factory()->create();

    withToken($token)->postJson('/api/v1/teacher-assignments', assignmentCreatePayload(['academic_session_id' => $active->id]))
        ->assertCreated();

    withToken($token)->postJson('/api/v1/teacher-assignments', assignmentCreatePayload(['academic_session_id' => $upcoming->id]))
        ->assertCreated();
});

it('refuses a duplicate active assignment for the same class subject and session', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $classSubject = activeClassSubject();
    $session = eligibleSession();

    withToken($token)->postJson('/api/v1/teacher-assignments', assignmentCreatePayload([
        'class_subject_id' => $classSubject->id,
        'academic_session_id' => $session->id,
    ]))->assertCreated();

    // A DIFFERENT teacher for the same class subject and session is still refused: the rule
    // is "at most one active assignment", not "one per teacher".
    withToken($token)->postJson('/api/v1/teacher-assignments', assignmentCreatePayload([
        'class_subject_id' => $classSubject->id,
        'academic_session_id' => $session->id,
    ]))->assertUnprocessable()->assertJsonValidationErrors('class_subject_id');

    expect(TeacherAssignment::query()->where('class_subject_id', $classSubject->id)->count())->toBe(1);
});

it('allows the same teacher to be assigned to multiple different class subjects', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $teacher = eligibleTeacher();
    $mathJss1 = activeClassSubject();
    $mathJss2 = activeClassSubject();

    withToken($token)->postJson('/api/v1/teacher-assignments', assignmentCreatePayload([
        'teaching_staff_id' => $teacher->id,
        'class_subject_id' => $mathJss1->id,
    ]))->assertCreated();

    withToken($token)->postJson('/api/v1/teacher-assignments', assignmentCreatePayload([
        'teaching_staff_id' => $teacher->id,
        'class_subject_id' => $mathJss2->id,
    ]))->assertCreated();

    expect(TeacherAssignment::query()->where('teaching_staff_id', $teacher->id)->count())->toBe(2);
});

it('allows the same class subject to be assigned across different sessions', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $classSubject = activeClassSubject();
    $first = eligibleSession();
    $second = eligibleSession();

    withToken($token)->postJson('/api/v1/teacher-assignments', assignmentCreatePayload([
        'class_subject_id' => $classSubject->id,
        'academic_session_id' => $first->id,
    ]))->assertCreated();

    withToken($token)->postJson('/api/v1/teacher-assignments', assignmentCreatePayload([
        'class_subject_id' => $classSubject->id,
        'academic_session_id' => $second->id,
    ]))->assertCreated();

    expect(TeacherAssignment::query()->where('class_subject_id', $classSubject->id)->count())->toBe(2);
});

it('has no field through which a create request can set the status', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    $response = withToken($token)->postJson('/api/v1/teacher-assignments', assignmentCreatePayload(['status' => 'ENDED']));

    $response->assertCreated()->assertJsonPath('data.status', TeacherAssignmentStatus::ACTIVE->value);
});

it('shows a single assignment', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $assignment = activeAssignment();

    withToken($token)->getJson("/api/v1/teacher-assignments/{$assignment->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $assignment->id);
});

it('answers 404 for an assignment that does not exist', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    withToken($token)->getJson('/api/v1/teacher-assignments/999999')->assertNotFound();
});

it('lists assignments newest first', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    $first = activeAssignment();
    $second = activeAssignment();

    $response = withToken($token)->getJson('/api/v1/teacher-assignments');

    $response->assertOk();

    expect(array_column($response->json('data'), 'id'))->toBe([$second->id, $first->id]);
});

it('amends the notes of an active assignment', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $assignment = activeAssignment();

    withToken($token)->putJson("/api/v1/teacher-assignments/{$assignment->id}", ['notes' => 'Covering for a colleague on leave.'])
        ->assertOk()
        ->assertJsonPath('data.notes', 'Covering for a colleague on leave.');

    expect($assignment->refresh()->notes)->toBe('Covering for a colleague on leave.');
});

it('cannot reach teaching_staff_id, class_subject_id, academic_session_id or status through the amend endpoint', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $assignment = activeAssignment();
    $otherTeacher = eligibleTeacher();
    $otherClassSubject = activeClassSubject();
    $otherSession = AcademicSession::factory()->create();

    withToken($token)->putJson("/api/v1/teacher-assignments/{$assignment->id}", [
        'notes' => 'x',
        'teaching_staff_id' => $otherTeacher->id,
        'class_subject_id' => $otherClassSubject->id,
        'academic_session_id' => $otherSession->id,
        'status' => 'ENDED',
    ])->assertOk();

    $assignment->refresh();

    expect($assignment->teaching_staff_id)->not->toBe($otherTeacher->id)
        ->and($assignment->class_subject_id)->not->toBe($otherClassSubject->id)
        ->and($assignment->academic_session_id)->not->toBe($otherSession->id)
        ->and($assignment->status)->toBe(TeacherAssignmentStatus::ACTIVE);
});

it('refuses to amend a terminal assignment', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $assignment = decidedAssignment(TeacherAssignmentStatus::ENDED);

    withToken($token)->putJson("/api/v1/teacher-assignments/{$assignment->id}", ['notes' => 'Changed'])
        ->assertStatus(422);

    expect($assignment->refresh()->notes)->not->toBe('Changed');
});

it('answers 405 for a PATCH', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $assignment = activeAssignment();

    withToken($token)->patchJson("/api/v1/teacher-assignments/{$assignment->id}", ['notes' => 'x'])
        ->assertStatus(405)
        ->assertHeader('Allow');
});

it('refuses a delete outright, because an assignment is academic history', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $assignment = activeAssignment();

    withToken($token)->deleteJson("/api/v1/teacher-assignments/{$assignment->id}")->assertStatus(405);

    expect(TeacherAssignment::query()->whereKey($assignment->id)->exists())->toBeTrue();
});

it('has no delete permission to grant in the first place', function (): void {
    expect(TeacherAssignmentPermissionSeeder::names())
        ->toContain('teacher_assignments.view', 'teacher_assignments.create', 'teacher_assignments.update', 'teacher_assignments.end', 'teacher_assignments.cancel')
        ->not->toContain('teacher_assignments.delete');
});

/*
|--------------------------------------------------------------------------
| Reassignment: end the old, create the new, both remain historically valid
|--------------------------------------------------------------------------
*/

it('supports reassignment by ending the current assignment and creating a new one for the same class subject and session', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $classSubject = activeClassSubject();
    $session = eligibleSession();
    $teacherA = eligibleTeacher();
    $teacherB = eligibleTeacher();

    $first = withToken($token)->postJson('/api/v1/teacher-assignments', assignmentCreatePayload([
        'teaching_staff_id' => $teacherA->id,
        'class_subject_id' => $classSubject->id,
        'academic_session_id' => $session->id,
    ]))->assertCreated();

    $firstId = $first->json('data.id');

    withToken($token)->postJson("/api/v1/teacher-assignments/{$firstId}/end", ['notes' => 'Transferred to another school.'])
        ->assertOk()
        ->assertJsonPath('data.status', 'ENDED');

    $second = withToken($token)->postJson('/api/v1/teacher-assignments', assignmentCreatePayload([
        'teaching_staff_id' => $teacherB->id,
        'class_subject_id' => $classSubject->id,
        'academic_session_id' => $session->id,
    ]))->assertCreated();

    // The old assignment remains historically correct - it is not deleted, and it still
    // names Teacher A - while the new one is the current, active record for this pair.
    $old = TeacherAssignment::query()->findOrFail($firstId);
    $new = TeacherAssignment::query()->findOrFail($second->json('data.id'));

    expect($old->status)->toBe(TeacherAssignmentStatus::ENDED)
        ->and($old->teaching_staff_id)->toBe($teacherA->id)
        ->and($new->status)->toBe(TeacherAssignmentStatus::ACTIVE)
        ->and($new->teaching_staff_id)->toBe($teacherB->id)
        ->and(TeacherAssignment::query()->where('class_subject_id', $classSubject->id)->count())->toBe(2);
});

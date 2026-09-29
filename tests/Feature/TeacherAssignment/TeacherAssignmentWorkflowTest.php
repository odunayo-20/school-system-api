<?php

use App\Enums\Role;
use App\Enums\TeacherAssignmentStatus;
use App\Exceptions\BusinessRuleViolation;
use App\Models\TeacherAssignment;
use App\Services\Staff\TeacherAssignmentService;

/*
|--------------------------------------------------------------------------
| Teacher assignment workflow: end, cancel (Module 08)
|--------------------------------------------------------------------------
*/

it('ends an active assignment', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $assignment = activeAssignment();

    withToken($token)->postJson("/api/v1/teacher-assignments/{$assignment->id}/end", ['notes' => 'Left the school mid-term.'])
        ->assertOk()
        ->assertJsonPath('data.status', TeacherAssignmentStatus::ENDED->value)
        ->assertJsonPath('data.notes', 'Left the school mid-term.');

    expect($assignment->refresh()->status)->toBe(TeacherAssignmentStatus::ENDED)
        ->and($assignment->ended_at)->not->toBeNull()
        ->and($assignment->active_marker)->toBeNull();
});

it('cancels an active assignment as the delete-replacement', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $assignment = activeAssignment();

    withToken($token)->postJson("/api/v1/teacher-assignments/{$assignment->id}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', TeacherAssignmentStatus::CANCELLED->value);

    expect($assignment->refresh()->status)->toBe(TeacherAssignmentStatus::CANCELLED)
        ->and(TeacherAssignment::query()->whereKey($assignment->id)->exists())->toBeTrue();
});

it('refuses to end an assignment twice', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $assignment = decidedAssignment(TeacherAssignmentStatus::ENDED);

    withToken($token)->postJson("/api/v1/teacher-assignments/{$assignment->id}/end")->assertStatus(422);

    expect($assignment->refresh()->status)->toBe(TeacherAssignmentStatus::ENDED);
});

it('refuses to cancel an assignment that was already ended', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $assignment = decidedAssignment(TeacherAssignmentStatus::ENDED);

    withToken($token)->postJson("/api/v1/teacher-assignments/{$assignment->id}/cancel")->assertStatus(422);

    expect($assignment->refresh()->status)->toBe(TeacherAssignmentStatus::ENDED);
});

it('refuses to end an assignment that was already cancelled', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $assignment = decidedAssignment(TeacherAssignmentStatus::CANCELLED);

    withToken($token)->postJson("/api/v1/teacher-assignments/{$assignment->id}/end")->assertStatus(422);

    expect($assignment->refresh()->status)->toBe(TeacherAssignmentStatus::CANCELLED);
});

it('names the current status in the refusal message', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $assignment = decidedAssignment(TeacherAssignmentStatus::CANCELLED);

    withToken($token)->postJson("/api/v1/teacher-assignments/{$assignment->id}/end")
        ->assertStatus(422)
        ->assertJsonPath('message', 'This teaching assignment has already ended (CANCELLED), so it cannot be ended.');
});

it('frees the class subject and session for a new assignment once the old one ends', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $assignment = activeAssignment();

    withToken($token)->postJson("/api/v1/teacher-assignments/{$assignment->id}/end")->assertOk();

    withToken($token)->postJson('/api/v1/teacher-assignments', assignmentCreatePayload([
        'class_subject_id' => $assignment->class_subject_id,
        'academic_session_id' => $assignment->academic_session_id,
    ]))->assertCreated();

    expect(TeacherAssignment::query()->where('class_subject_id', $assignment->class_subject_id)->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Authorization on the workflow endpoints
|--------------------------------------------------------------------------
*/

it('gates end and cancel behind their own permissions', function (): void {
    foreach ([Role::STAFF, Role::STUDENT, Role::REGISTRAR] as $role) {
        $assignment = activeAssignment();
        $token = loginAs(userWithRole($role));

        withToken($token)->postJson("/api/v1/teacher-assignments/{$assignment->id}/end")->assertForbidden();
        withToken($token)->postJson("/api/v1/teacher-assignments/{$assignment->id}/cancel")->assertForbidden();

        expect($assignment->refresh()->status)->toBe(TeacherAssignmentStatus::ACTIVE);
    }
});

it('grants the full workflow to super admin and admin', function (): void {
    foreach ([Role::SUPER_ADMIN, Role::ADMIN] as $role) {
        $assignment = activeAssignment();
        $token = loginAs(userWithRole($role));

        withToken($token)->postJson("/api/v1/teacher-assignments/{$assignment->id}/end")->assertOk();
    }
});

it('refuses every transition to an unauthenticated caller', function (): void {
    $assignment = activeAssignment();

    test()->postJson("/api/v1/teacher-assignments/{$assignment->id}/end")->assertUnauthorized();
    test()->postJson("/api/v1/teacher-assignments/{$assignment->id}/cancel")->assertUnauthorized();
});

/*
|--------------------------------------------------------------------------
| Concurrency / race protection
|--------------------------------------------------------------------------
*/

it('lets the database catch a duplicate active assignment that validation could not, and reports it as a 422', function (): void {
    // Simulates the race two concurrent requests would create: both pass validation (neither
    // sees the other's row yet), and the second insert is the one that must fail cleanly.
    // Calling the service directly bypasses the form request's own Rule::unique check, the
    // same technique EnrollmentWorkflowTest and SubjectIntegrityTest use for their own
    // unique-index races.
    $service = app(TeacherAssignmentService::class);
    $assignment = activeAssignment();

    expect(fn () => $service->create([
        'teaching_staff_id' => eligibleTeacher()->id,
        'class_subject_id' => $assignment->class_subject_id,
        'academic_session_id' => $assignment->academic_session_id,
    ]))->toThrow(BusinessRuleViolation::class, 'This class subject already has an active teacher for this academic session. End that assignment first.');

    expect(TeacherAssignment::query()->where('class_subject_id', $assignment->class_subject_id)->count())->toBe(1);
});

it('is idempotent at the service layer: assertActive is the single gate for both transitions', function (): void {
    $service = app(TeacherAssignmentService::class);
    $assignment = decidedAssignment(TeacherAssignmentStatus::CANCELLED);

    expect(fn () => $service->end($assignment))->toThrow(BusinessRuleViolation::class)
        ->and(fn () => $service->cancel($assignment))->toThrow(BusinessRuleViolation::class);
});

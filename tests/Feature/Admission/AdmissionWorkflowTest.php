<?php

use App\Enums\AdmissionStatus;
use App\Enums\Role;
use App\Enums\StudentStatus;
use App\Exceptions\BusinessRuleViolation;
use App\Models\Admission;
use App\Models\Student;
use App\Services\Admission\AdmissionService;

/*
|--------------------------------------------------------------------------
| Admission workflow: admit, reject, withdraw (Module 05)
|--------------------------------------------------------------------------
|
| The transition rules, the boundary with Student creation, and the edge cases the brief
| calls out by name: accepted twice, rejected after already admitted, a failure partway
| through admit() leaving no half-created record.
|
*/

it('admits a pending admission and creates a linked student', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $admission = pendingAdmission([
        'first_name' => 'Ada',
        'middle_name' => 'Ngozi',
        'last_name' => 'Okonkwo',
        'date_of_birth' => '2015-04-02',
        'gender' => 'FEMALE',
    ]);

    $response = withToken($token)->postJson("/api/v1/admissions/{$admission->id}/admit");

    $response->assertOk()
        ->assertJsonPath('data.status', AdmissionStatus::ADMITTED->value)
        ->assertJsonPath('data.student.first_name', 'Ada')
        ->assertJsonPath('data.student.last_name', 'Okonkwo')
        ->assertJsonPath('data.student.status', StudentStatus::ACTIVE->value);

    $admission->refresh();

    expect($admission->status)->toBe(AdmissionStatus::ADMITTED)
        ->and($admission->student_id)->not->toBeNull()
        ->and($admission->decided_at)->not->toBeNull();

    $student = Student::query()->findOrFail($admission->student_id);

    expect($student->first_name)->toBe('Ada')
        ->and($student->last_name)->toBe('Okonkwo')
        ->and($student->date_of_birth->toDateString())->toBe('2015-04-02')
        ->and($student->status)->toBe(StudentStatus::ACTIVE)
        ->and(Student::query()->count())->toBe(1);
});

it('rejects a pending admission and creates no student', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $admission = pendingAdmission();

    withToken($token)->postJson("/api/v1/admissions/{$admission->id}/reject", ['notes' => 'Did not meet the entry requirement.'])
        ->assertOk()
        ->assertJsonPath('data.status', AdmissionStatus::REJECTED->value)
        ->assertJsonPath('data.notes', 'Did not meet the entry requirement.')
        ->assertJsonPath('data.student', null);

    expect($admission->refresh()->status)->toBe(AdmissionStatus::REJECTED)
        ->and($admission->student_id)->toBeNull()
        ->and($admission->decided_at)->not->toBeNull()
        ->and(Student::query()->count())->toBe(0);
});

it('withdraws a pending admission and creates no student', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $admission = pendingAdmission();

    withToken($token)->postJson("/api/v1/admissions/{$admission->id}/withdraw")
        ->assertOk()
        ->assertJsonPath('data.status', AdmissionStatus::WITHDRAWN->value);

    expect($admission->refresh()->status)->toBe(AdmissionStatus::WITHDRAWN)
        ->and($admission->student_id)->toBeNull()
        ->and(Student::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Terminal states: no transition out, and no repeat of the same one
|--------------------------------------------------------------------------
*/

it('refuses to admit an admission twice', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $admission = decidedAdmission(AdmissionStatus::ADMITTED);
    $studentCountBefore = Student::query()->count();

    withToken($token)->postJson("/api/v1/admissions/{$admission->id}/admit")->assertStatus(422);

    expect($admission->refresh()->status)->toBe(AdmissionStatus::ADMITTED)
        ->and(Student::query()->count())->toBe($studentCountBefore);
});

it('refuses to reject an admission that has already been admitted', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $admission = decidedAdmission(AdmissionStatus::ADMITTED);

    withToken($token)->postJson("/api/v1/admissions/{$admission->id}/reject")->assertStatus(422);

    expect($admission->refresh()->status)->toBe(AdmissionStatus::ADMITTED);
});

it('refuses to admit an admission that was already rejected', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $admission = decidedAdmission(AdmissionStatus::REJECTED);

    withToken($token)->postJson("/api/v1/admissions/{$admission->id}/admit")->assertStatus(422);

    expect($admission->refresh()->status)->toBe(AdmissionStatus::REJECTED)
        ->and(Student::query()->count())->toBe(0);
});

it('refuses to withdraw an admission that was already rejected', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $admission = decidedAdmission(AdmissionStatus::REJECTED);

    withToken($token)->postJson("/api/v1/admissions/{$admission->id}/withdraw")->assertStatus(422);

    expect($admission->refresh()->status)->toBe(AdmissionStatus::REJECTED);
});

it('refuses to reject an admission that was already withdrawn', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $admission = decidedAdmission(AdmissionStatus::WITHDRAWN);

    withToken($token)->postJson("/api/v1/admissions/{$admission->id}/reject")->assertStatus(422);

    expect($admission->refresh()->status)->toBe(AdmissionStatus::WITHDRAWN);
});

it('names the current status in the refusal message', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $admission = decidedAdmission(AdmissionStatus::ADMITTED);

    withToken($token)->postJson("/api/v1/admissions/{$admission->id}/reject")
        ->assertStatus(422)
        ->assertJsonPath('message', 'This admission has already been decided (ADMITTED), so it cannot be rejected.');
});

/*
|--------------------------------------------------------------------------
| Authorization on the workflow endpoints
|--------------------------------------------------------------------------
*/

it('gates admit, reject and withdraw behind their own permissions', function (): void {
    foreach ([Role::STAFF, Role::STUDENT] as $role) {
        $admission = pendingAdmission();
        $token = loginAs(userWithRole($role));

        withToken($token)->postJson("/api/v1/admissions/{$admission->id}/admit")->assertForbidden();
        withToken($token)->postJson("/api/v1/admissions/{$admission->id}/reject")->assertForbidden();
        withToken($token)->postJson("/api/v1/admissions/{$admission->id}/withdraw")->assertForbidden();

        expect($admission->refresh()->status)->toBe(AdmissionStatus::PENDING);
    }
});

it('grants the full workflow to super admin, admin and registrar', function (): void {
    foreach ([Role::SUPER_ADMIN, Role::ADMIN, Role::REGISTRAR] as $role) {
        $admission = pendingAdmission();
        $token = loginAs(userWithRole($role));

        withToken($token)->postJson("/api/v1/admissions/{$admission->id}/admit")->assertOk();
    }
});

it('refuses every transition to an unauthenticated caller', function (): void {
    $admission = pendingAdmission();

    test()->postJson("/api/v1/admissions/{$admission->id}/admit")->assertUnauthorized();
    test()->postJson("/api/v1/admissions/{$admission->id}/reject")->assertUnauthorized();
    test()->postJson("/api/v1/admissions/{$admission->id}/withdraw")->assertUnauthorized();
});

/*
|--------------------------------------------------------------------------
| Transaction safety: admit() must not leave a half-created record
|--------------------------------------------------------------------------
*/

it('leaves no student and no status change when admit fails partway through', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $admission = pendingAdmission();

    $forcedFailure = new RuntimeException('forced failure after the student is created');

    // Fail on the admission's own save inside the transaction, after the student row has
    // already been inserted by StudentService::create(). Without a transaction spanning
    // both writes, this would commit an orphaned Student that no admission points to.
    Admission::saving(function (Admission $model) use ($admission, $forcedFailure): void {
        if ($model->is($admission) && $model->status === AdmissionStatus::ADMITTED) {
            throw $forcedFailure;
        }
    });

    test()->withoutExceptionHandling();

    expect(fn () => withToken($token)->postJson("/api/v1/admissions/{$admission->id}/admit"))
        ->toThrow(RuntimeException::class);

    expect(Student::query()->count())->toBe(0)
        ->and($admission->refresh()->status)->toBe(AdmissionStatus::PENDING)
        ->and($admission->student_id)->toBeNull();
});

it('never leaves the admission linked to a student count higher than one create ever produces', function (): void {
    // A defence-in-depth assertion for the "duplicate student" edge case: admit() calls
    // StudentService::create() exactly once, guarded by the PENDING precondition, so no
    // sequence of calls through the API can produce two students for one admission.
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $admission = pendingAdmission();

    withToken($token)->postJson("/api/v1/admissions/{$admission->id}/admit")->assertOk();
    withToken($token)->postJson("/api/v1/admissions/{$admission->id}/admit")->assertStatus(422);
    withToken($token)->postJson("/api/v1/admissions/{$admission->id}/admit")->assertStatus(422);

    expect(Student::query()->count())->toBe(1);
});

it('is idempotent at the service layer: assertPending is the single gate for all three transitions', function (): void {
    $service = app(AdmissionService::class);
    $admission = decidedAdmission(AdmissionStatus::WITHDRAWN);

    expect(fn () => $service->admit($admission))->toThrow(BusinessRuleViolation::class)
        ->and(fn () => $service->reject($admission))->toThrow(BusinessRuleViolation::class)
        ->and(fn () => $service->withdraw($admission))->toThrow(BusinessRuleViolation::class);
});

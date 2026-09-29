<?php

use App\Enums\AdmissionStatus;
use App\Enums\CatalogStatus;
use App\Enums\Role;
use App\Models\AcademicSession;
use App\Models\Admission;
use App\Models\ClassLevel;
use App\Models\Student;
use App\Services\Admission\AdmissionService;
use Database\Seeders\AdmissionPermissionSeeder;

/*
|--------------------------------------------------------------------------
| Admission management: create, read, amend (Module 05)
|--------------------------------------------------------------------------
*/

it('records a new admission as pending, with no student created', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    $session = AcademicSession::factory()->create();

    $response = withToken($token)->postJson('/api/v1/admissions', admissionCreatePayload([
        'academic_session_id' => $session->id,
        'middle_name' => 'Ngozi',
        'date_of_birth' => '2015-04-02',
        'gender' => 'FEMALE',
    ]));

    $response->assertCreated()
        ->assertJsonPath('data.first_name', 'Amina')
        ->assertJsonPath('data.full_name', 'Amina Ngozi Yusuf')
        ->assertJsonPath('data.status', AdmissionStatus::PENDING->value)
        ->assertJsonPath('data.student', null)
        ->assertJsonPath('data.academic_session.id', $session->id);

    expect(Admission::query()->count())->toBe(1)
        ->and(Student::query()->count())->toBe(0);
});

it('derives a unique admission number when the client omits one', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    $first = withToken($token)->postJson('/api/v1/admissions', admissionCreatePayload());
    $second = withToken($token)->postJson('/api/v1/admissions', admissionCreatePayload());

    $first->assertCreated();
    $second->assertCreated();

    expect($first->json('data.admission_number'))->toBe('ADM-'.str_pad((string) $first->json('data.id'), 4, '0', STR_PAD_LEFT))
        ->and($second->json('data.admission_number'))->not->toBe($first->json('data.admission_number'));

    expect(Admission::query()->where('admission_number', 'like', 'ADM-TMP%')->exists())->toBeFalse();
});

it('accepts a client supplied admission number and normalises its spelling', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    $response = withToken($token)->postJson('/api/v1/admissions', admissionCreatePayload([
        'admission_number' => '  adm-0042 ',
    ]));

    $response->assertCreated()->assertJsonPath('data.admission_number', 'ADM-0042');

    withToken($token)->postJson('/api/v1/admissions', admissionCreatePayload([
        'admission_number' => 'ADM-0042',
    ]))->assertUnprocessable()->assertJsonValidationErrors('admission_number');
});

it('requires a first name', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    withToken($token)->postJson('/api/v1/admissions', admissionCreatePayload(['first_name' => null]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('first_name');

    expect(Admission::query()->count())->toBe(0);
});

it('requires an academic session', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    $payload = admissionCreatePayload();
    unset($payload['academic_session_id']);

    withToken($token)->postJson('/api/v1/admissions', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('academic_session_id');
});

it('refuses an academic session that does not exist', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    withToken($token)->postJson('/api/v1/admissions', admissionCreatePayload(['academic_session_id' => 999999]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('academic_session_id');
});

it('refuses an academic session that has already completed', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $completed = AcademicSession::factory()->completed()->create();

    withToken($token)->postJson('/api/v1/admissions', admissionCreatePayload([
        'academic_session_id' => $completed->id,
    ]))->assertUnprocessable()->assertJsonValidationErrors('academic_session_id');

    expect(Admission::query()->count())->toBe(0);
});

it('accepts an admission targeting the active session or an upcoming one', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    $active = AcademicSession::factory()->active()->create();
    $upcoming = AcademicSession::factory()->create();

    withToken($token)->postJson('/api/v1/admissions', admissionCreatePayload(['academic_session_id' => $active->id]))
        ->assertCreated();

    withToken($token)->postJson('/api/v1/admissions', admissionCreatePayload(['academic_session_id' => $upcoming->id]))
        ->assertCreated();
});

it('accepts an optional entry class level that is active', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $level = selectableClassLevel();

    withToken($token)->postJson('/api/v1/admissions', admissionCreatePayload([
        'entry_class_level_id' => $level->id,
    ]))->assertCreated()->assertJsonPath('data.entry_class_level.id', $level->id);
});

it('refuses an entry class level that has been retired', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $archived = ClassLevel::factory()->status(CatalogStatus::ARCHIVED)->create();

    withToken($token)->postJson('/api/v1/admissions', admissionCreatePayload([
        'entry_class_level_id' => $archived->id,
    ]))->assertUnprocessable()->assertJsonValidationErrors('entry_class_level_id');
});

it('refuses a date of birth in the future', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    withToken($token)->postJson('/api/v1/admissions', admissionCreatePayload([
        'date_of_birth' => now()->addDay()->toDateString(),
    ]))->assertUnprocessable()->assertJsonValidationErrors('date_of_birth');
});

it('refuses a gender the enum does not define', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    withToken($token)->postJson('/api/v1/admissions', admissionCreatePayload(['gender' => 'OTHER']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('gender');
});

it('has no field through which a create request can set the status or the student', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    $response = withToken($token)->postJson('/api/v1/admissions', admissionCreatePayload([
        'status' => 'ADMITTED',
        'student_id' => 999,
    ]));

    $response->assertCreated()->assertJsonPath('data.status', AdmissionStatus::PENDING->value);

    expect(Admission::query()->sole()->status)->toBe(AdmissionStatus::PENDING)
        ->and(Admission::query()->sole()->student_id)->toBeNull();
});

it('shows a single admission', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $admission = pendingAdmission(['first_name' => 'Ada', 'last_name' => 'Okonkwo']);

    withToken($token)->getJson("/api/v1/admissions/{$admission->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $admission->id)
        ->assertJsonPath('data.first_name', 'Ada');
});

it('answers 404 for an admission that does not exist', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    withToken($token)->getJson('/api/v1/admissions/999999')->assertNotFound();
});

it('lists admissions newest first', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    $first = pendingAdmission();
    $second = pendingAdmission();

    $response = withToken($token)->getJson('/api/v1/admissions');

    $response->assertOk();

    expect(array_column($response->json('data'), 'id'))->toBe([$second->id, $first->id]);
});

it('amends a pending admission', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $admission = pendingAdmission(['first_name' => 'Amina', 'last_name' => 'Yusuf']);

    withToken($token)->putJson("/api/v1/admissions/{$admission->id}", admissionUpdatePayload([
        'first_name' => 'Amina',
        'last_name' => 'Okonkwo',
        'academic_session_id' => $admission->academic_session_id,
    ]))->assertOk()->assertJsonPath('data.last_name', 'Okonkwo');

    expect($admission->refresh()->last_name)->toBe('Okonkwo');
});

it('treats the amend as a whole record write, so omitting the first name is refused', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $admission = pendingAdmission();

    withToken($token)->putJson("/api/v1/admissions/{$admission->id}", [
        'academic_session_id' => $admission->academic_session_id,
    ])->assertUnprocessable()->assertJsonValidationErrors('first_name');
});

it('cannot reach the status through the amend endpoint', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $admission = pendingAdmission();

    withToken($token)->putJson("/api/v1/admissions/{$admission->id}", admissionUpdatePayload([
        'academic_session_id' => $admission->academic_session_id,
        'status' => 'ADMITTED',
    ]))->assertOk();

    // The key is not in the request's attribute set, so it cannot reach the model - the
    // same protection UpdateStudentRequest gives user_id.
    expect($admission->refresh()->status)->toBe(AdmissionStatus::PENDING);
});

it('cannot reach student_id through the amend endpoint', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $admission = pendingAdmission();
    $someoneElsesStudent = Student::factory()->create();

    withToken($token)->putJson("/api/v1/admissions/{$admission->id}", admissionUpdatePayload([
        'academic_session_id' => $admission->academic_session_id,
        'student_id' => $someoneElsesStudent->id,
    ]))->assertOk();

    expect($admission->refresh()->student_id)->toBeNull();
});

it('refuses to amend a decided admission', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $admission = decidedAdmission(AdmissionStatus::REJECTED);

    withToken($token)->putJson("/api/v1/admissions/{$admission->id}", admissionUpdatePayload([
        'academic_session_id' => $admission->academic_session_id,
        'last_name' => 'Changed',
    ]))->assertStatus(422);

    expect($admission->refresh()->last_name)->not->toBe('Changed');
});

it('answers 405 for a PATCH', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $admission = pendingAdmission();

    withToken($token)->patchJson("/api/v1/admissions/{$admission->id}", admissionUpdatePayload())
        ->assertStatus(405)
        ->assertHeader('Allow');
});

it('refuses a delete outright, because an admission is a historical business record', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $admission = pendingAdmission();

    withToken($token)->deleteJson("/api/v1/admissions/{$admission->id}")->assertStatus(405);

    expect(Admission::query()->whereKey($admission->id)->exists())->toBeTrue();
});

it('has no delete permission to grant in the first place', function (): void {
    expect(AdmissionPermissionSeeder::names())
        ->toContain('admissions.view', 'admissions.create', 'admissions.update', 'admissions.admit', 'admissions.reject', 'admissions.withdraw')
        ->not->toContain('admissions.delete');
});

it('derives a number from the admission id and not from a count', function (): void {
    $service = app(AdmissionService::class);

    expect($service->deriveAdmissionNumber(7))->toBe('ADM-0007')
        ->and($service->deriveAdmissionNumber(1234))->toBe('ADM-1234');
});

it('reads back a name trimmed on the way in', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    $response = withToken($token)->postJson('/api/v1/admissions', admissionCreatePayload([
        'first_name' => '  Ada  ',
    ]));

    $response->assertCreated()->assertJsonPath('data.first_name', 'Ada');
});

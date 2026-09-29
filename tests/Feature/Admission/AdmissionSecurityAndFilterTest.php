<?php

use App\Enums\AdmissionStatus;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\AcademicSession;
use App\Models\Admission;
use App\Models\Permission;
use App\Models\Role as RoleModel;
use App\Models\Student;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Admission authorization, filters and exposure (Module 05)
|--------------------------------------------------------------------------
*/

/*
| Authorization
*/

it('refuses admissions to an unauthenticated caller', function (): void {
    pendingAdmission();

    test()->getJson('/api/v1/admissions')->assertUnauthorized();
    test()->postJson('/api/v1/admissions', admissionCreatePayload())->assertUnauthorized();
});

it('refuses admissions to a user with no admissions permission', function (): void {
    $teacher = userWithRole(Role::STAFF);
    $token = loginAs($teacher);

    withToken($token)->getJson('/api/v1/admissions')->assertForbidden();
    withToken($token)->postJson('/api/v1/admissions', admissionCreatePayload())->assertForbidden();
});

it('refuses admissions to a suspended account, even with a token minted before the suspension', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);

    $admin->forceFill(['status' => UserStatus::SUSPENDED])->save();
    forgetResolvedUser();

    withToken($token)->getJson('/api/v1/admissions')->assertForbidden();
});

it('grants admissions to super admin, admin and registrar', function (): void {
    foreach ([Role::SUPER_ADMIN, Role::ADMIN, Role::REGISTRAR] as $role) {
        $token = loginAs(userWithRole($role));

        withToken($token)->getJson('/api/v1/admissions')->assertOk();
        withToken($token)->postJson('/api/v1/admissions', admissionCreatePayload())->assertCreated();
    }
});

it('keeps admissions away from a pupil account', function (): void {
    $token = loginAs(userWithRole(Role::STUDENT));

    withToken($token)->getJson('/api/v1/admissions')->assertForbidden();
    withToken($token)->postJson('/api/v1/admissions', admissionCreatePayload())->assertForbidden();
});

it('requires a specific permission per action rather than one blanket grant', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $admission = pendingAdmission();

    $admin->role->permissions()->detach(
        Permission::query()->where('name', 'admissions.create')->value('id')
    );

    $token = loginAs($admin);

    withToken($token)->getJson('/api/v1/admissions')->assertOk();
    withToken($token)->postJson('/api/v1/admissions', admissionCreatePayload())->assertForbidden();
    withToken($token)->putJson("/api/v1/admissions/{$admission->id}", admissionUpdatePayload([
        'academic_session_id' => $admission->academic_session_id,
    ]))->assertOk();
});

it('separates the admit permission from update and view', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $admission = pendingAdmission();

    $admin->role->permissions()->detach(
        Permission::query()->where('name', 'admissions.admit')->value('id')
    );

    $token = loginAs($admin);

    withToken($token)->getJson('/api/v1/admissions')->assertOk();
    withToken($token)->putJson("/api/v1/admissions/{$admission->id}", admissionUpdatePayload([
        'academic_session_id' => $admission->academic_session_id,
    ]))->assertOk();
    withToken($token)->postJson("/api/v1/admissions/{$admission->id}/admit")->assertForbidden();

    expect($admission->refresh()->status)->toBe(AdmissionStatus::PENDING);
});

it('seeds the permissions this module owns and no others', function (): void {
    $names = Permission::query()->where('name', 'like', 'admissions.%')->pluck('name')->all();

    expect($names)->toEqualCanonicalizing([
        'admissions.view', 'admissions.create', 'admissions.update',
        'admissions.admit', 'admissions.reject', 'admissions.withdraw',
    ]);
});

it('adds the module permissions without revoking the earlier modules', function (): void {
    $admin = RoleModel::query()->where('name', Role::ADMIN->value)->firstOrFail();
    $held = $admin->permissions()->pluck('name')->all();

    expect($held)->toContain('school.view')          // Module 01/02
        ->toContain('staff.view')                    // Module 03
        ->toContain('students.view')                 // Module 04
        ->toContain('admissions.view')                // Module 05
        ->not->toContain('admissions.delete');
});

/*
| Filters
*/

it('finds an admission by any part of the applicant name', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    $target = pendingAdmission(['first_name' => 'Amina', 'middle_name' => 'Ngozi', 'last_name' => 'Okonkwo']);
    pendingAdmission(['first_name' => 'Bello', 'last_name' => 'Sani']);

    foreach (['amina', 'Ngozi', 'okonkwo'] as $term) {
        $response = withToken($token)->getJson("/api/v1/admissions?search={$term}");

        $response->assertOk();

        expect($response->json('data'))->toHaveCount(1)
            ->and($response->json('data.0.id'))->toBe($target->id);
    }
});

it('finds an admission by number', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $target = pendingAdmission(['admission_number' => 'ADM-0042']);

    pendingAdmission(['first_name' => 'Bello']);

    withToken($token)->getJson('/api/v1/admissions?search=ADM-0042')
        ->assertOk()
        ->assertJsonPath('data.0.id', $target->id);
});

it('does not let a wildcard in a search widen the query', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    pendingAdmission(['first_name' => 'Amina']);
    pendingAdmission(['first_name' => 'Bello']);

    withToken($token)->getJson('/api/v1/admissions?search=%25')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('filters by status', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    pendingAdmission();
    $rejected = decidedAdmission(AdmissionStatus::REJECTED);

    withToken($token)->getJson('/api/v1/admissions?status=REJECTED')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $rejected->id);
});

it('filters by academic session', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    $session = AcademicSession::factory()->create();
    $target = pendingAdmission(['academic_session_id' => $session->id]);
    pendingAdmission();

    withToken($token)->getJson("/api/v1/admissions?academic_session_id={$session->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $target->id);
});

it('filters by entry class level', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    $level = selectableClassLevel();
    $target = pendingAdmission(['entry_class_level_id' => $level->id]);
    pendingAdmission();

    withToken($token)->getJson("/api/v1/admissions?entry_class_level_id={$level->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $target->id);
});

it('rejects a status filter value the enum does not define, naming the permitted values', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    withToken($token)->getJson('/api/v1/admissions?status=SOMETHING_ELSE')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status')
        ->assertJsonPath('errors.status.0', 'The status must be one of: PENDING, ADMITTED, REJECTED, WITHDRAWN.');
});

it('rejects a filter naming an academic session that does not exist', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    withToken($token)->getJson('/api/v1/admissions?academic_session_id=999999')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('academic_session_id');
});

it('returns nothing rather than everything when a search matches nothing', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    pendingAdmission();

    withToken($token)->getJson('/api/v1/admissions?search=Nobody')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('pages the admissions list and caps the page size', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    Admission::factory()->count(25)->create();

    $first = withToken($token)->getJson('/api/v1/admissions?per_page=10&page=1');
    $first->assertOk()->assertJsonCount(10, 'data');

    expect($first->json('meta.total'))->toBe(25)
        ->and($first->json('meta.last_page'))->toBe(3);

    withToken($token)->getJson('/api/v1/admissions?per_page=500')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('per_page');
});

/*
| What the API will not do or show
*/

it('ignores an attempt to set student_id through the create endpoint', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $someoneElsesStudent = Student::factory()->create();

    withToken($token)->postJson('/api/v1/admissions', admissionCreatePayload([
        'student_id' => $someoneElsesStudent->id,
    ]))->assertCreated();

    expect(Admission::query()->sole()->student_id)->toBeNull();
});

it('ignores a mass-assignment attempt to inject a role or account fields', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $usersBefore = User::query()->count();

    withToken($token)->postJson('/api/v1/admissions', admissionCreatePayload([
        'role' => 'SUPER_ADMIN',
        'role_id' => 1,
        'email' => 'applicant@example.test',
        'password' => 'Str0ng!Passw0rd',
    ]))->assertCreated();

    expect(User::query()->count())->toBe($usersBefore)
        ->and(Admission::query()->sole()->getAttributes())->not->toHaveKey('role_id')
        ->and(Admission::query()->sole()->getAttributes())->not->toHaveKey('email');
});

it('exposes no account internals through the linked student once admitted', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $admission = pendingAdmission();

    $response = withToken($token)->postJson("/api/v1/admissions/{$admission->id}/admit");

    $response->assertOk();

    expect(array_keys($response->json('data.student')))
        ->toBe([
            'id', 'student_number',
            'first_name', 'middle_name', 'last_name', 'full_name',
            'date_of_birth', 'gender',
            'status', 'account_status',
            'created_at', 'updated_at',
        ]);
});

it('lists the admissions through the same envelope as every other list in the API', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    pendingAdmission();

    withToken($token)->getJson('/api/v1/admissions')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [['id', 'admission_number', 'first_name', 'full_name', 'status']],
            'meta' => ['current_page', 'per_page', 'total', 'last_page'],
        ]);
});

it('does not let an admission read or amend a different admission through IDOR', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $mine = pendingAdmission(['first_name' => 'Mine']);
    $someoneElses = pendingAdmission(['first_name' => 'TheirsUnchanged']);

    // There is no ownership scoping in this module - every admissions.view holder can read
    // every admission, by design (a registrar processes the whole queue, not "their own"
    // applicants) - so this test pins the actual boundary: a route parameter naming one
    // record's id cannot be used to write a DIFFERENT record.
    withToken($token)->putJson("/api/v1/admissions/{$mine->id}", admissionUpdatePayload([
        'academic_session_id' => $mine->academic_session_id,
        'first_name' => 'MineChanged',
    ]))->assertOk();

    expect($mine->refresh()->first_name)->toBe('MineChanged')
        ->and($someoneElses->refresh()->first_name)->toBe('TheirsUnchanged');
});

<?php

use App\Enums\CatalogStatus;
use App\Enums\Role;
use App\Models\Subject;
use Database\Seeders\SubjectPermissionSeeder;

/*
|--------------------------------------------------------------------------
| Subject catalogue: create, read, amend, delete (Module 07)
|--------------------------------------------------------------------------
*/

it('adds a subject to the catalogue', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    $response = withToken($token)->postJson('/api/v1/subjects', subjectCreatePayload([
        'name' => 'Mathematics',
        'code' => 'mat',
    ]));

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Mathematics')
        ->assertJsonPath('data.code', 'MAT')
        ->assertJsonPath('data.status', CatalogStatus::ACTIVE->value);

    expect(Subject::query()->count())->toBe(1);
});

it('requires a name', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    $payload = subjectCreatePayload();
    unset($payload['name']);

    withToken($token)->postJson('/api/v1/subjects', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');

    expect(Subject::query()->count())->toBe(0);
});

it('requires a code', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    $payload = subjectCreatePayload();
    unset($payload['code']);

    withToken($token)->postJson('/api/v1/subjects', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('code');
});

it('refuses a duplicate name', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    catalogSubject(['name' => 'Mathematics']);

    withToken($token)->postJson('/api/v1/subjects', subjectCreatePayload(['name' => 'Mathematics']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');

    expect(Subject::query()->count())->toBe(1);
});

it('refuses a duplicate code', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    catalogSubject(['code' => 'MAT']);

    withToken($token)->postJson('/api/v1/subjects', subjectCreatePayload(['code' => 'MAT']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('code');

    expect(Subject::query()->count())->toBe(1);
});

it('treats two codes with different casing as the same code', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    withToken($token)->postJson('/api/v1/subjects', subjectCreatePayload(['code' => 'mat']))
        ->assertCreated()
        ->assertJsonPath('data.code', 'MAT');

    // A second spelling of the same code is the same code, so it must be refused - otherwise
    // the unique index would be the only thing stopping it, and the error would surface as a
    // 500 rather than a 422.
    withToken($token)->postJson('/api/v1/subjects', subjectCreatePayload(['code' => 'MAT']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('code');

    expect(Subject::query()->count())->toBe(1);
});

it('trims whitespace from the code before storing it', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    withToken($token)->postJson('/api/v1/subjects', subjectCreatePayload(['code' => '  mat  ']))
        ->assertCreated()
        ->assertJsonPath('data.code', 'MAT');
});

it('rejects a code longer than the maximum length', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    withToken($token)->postJson('/api/v1/subjects', subjectCreatePayload(['code' => str_repeat('A', 21)]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('code');
});

it('rejects a name longer than the maximum length', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    withToken($token)->postJson('/api/v1/subjects', subjectCreatePayload(['name' => str_repeat('A', 101)]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');
});

it('rejects an invalid status', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    withToken($token)->postJson('/api/v1/subjects', subjectCreatePayload(['status' => 'DRAFT']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status')
        ->assertJsonPath('errors.status.0', 'The status must be one of: ACTIVE, INACTIVE, ARCHIVED.');
});

it('shows a single subject', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $subject = catalogSubject(['name' => 'Biology', 'code' => 'BIO']);

    withToken($token)->getJson("/api/v1/subjects/{$subject->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $subject->id)
        ->assertJsonPath('data.name', 'Biology');
});

it('answers 404 for a subject that does not exist', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    withToken($token)->getJson('/api/v1/subjects/999999')->assertNotFound();
});

it('lists subjects ordered by sort order then name', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    Subject::factory()->create(['name' => 'Zoology', 'sort_order' => 1]);
    Subject::factory()->create(['name' => 'Mathematics', 'sort_order' => 1]);
    Subject::factory()->create(['name' => 'Art', 'sort_order' => 2]);

    $response = withToken($token)->getJson('/api/v1/subjects');

    $response->assertOk();

    expect(array_column($response->json('data'), 'name'))->toBe(['Mathematics', 'Zoology', 'Art']);
});

it('returns an empty catalogue rather than an error when no subjects exist', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    withToken($token)->getJson('/api/v1/subjects')->assertOk()->assertJsonCount(0, 'data');
});

it('amends a subject', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $subject = catalogSubject(['name' => 'Mathematics', 'code' => 'MAT']);

    withToken($token)->putJson("/api/v1/subjects/{$subject->id}", subjectUpdatePayload([
        'name' => 'Further Mathematics',
        'code' => 'FMAT',
    ]))->assertOk()->assertJsonPath('data.name', 'Further Mathematics');

    expect($subject->refresh()->name)->toBe('Further Mathematics');
});

it('allows updating a subject without changing its own unique name or code', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $subject = catalogSubject(['name' => 'Mathematics', 'code' => 'MAT', 'sort_order' => 1]);

    withToken($token)->putJson("/api/v1/subjects/{$subject->id}", [
        'name' => 'Mathematics',
        'code' => 'MAT',
        'sort_order' => 5,
    ])->assertOk()->assertJsonPath('data.sort_order', 5);
});

it('refuses to amend a subject to a code already used by another subject', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    catalogSubject(['code' => 'ENG']);
    $subject = catalogSubject(['code' => 'MAT']);

    withToken($token)->putJson("/api/v1/subjects/{$subject->id}", subjectUpdatePayload(['code' => 'ENG']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('code');

    expect($subject->refresh()->code)->toBe('MAT');
});

it('treats the amend as a whole record write, so omitting the name is refused', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $subject = catalogSubject();

    withToken($token)->putJson("/api/v1/subjects/{$subject->id}", ['code' => $subject->code])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');
});

it('changes a subject status to inactive and back', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $subject = catalogSubject();

    withToken($token)->putJson("/api/v1/subjects/{$subject->id}", subjectUpdatePayload([
        'name' => $subject->name,
        'code' => $subject->code,
        'status' => 'INACTIVE',
    ]))->assertOk()->assertJsonPath('data.status', 'INACTIVE');

    withToken($token)->putJson("/api/v1/subjects/{$subject->id}", subjectUpdatePayload([
        'name' => $subject->name,
        'code' => $subject->code,
        'status' => 'ACTIVE',
    ]))->assertOk()->assertJsonPath('data.status', 'ACTIVE');
});

it('answers 405 for a PATCH', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $subject = catalogSubject();

    withToken($token)->patchJson("/api/v1/subjects/{$subject->id}", subjectUpdatePayload())
        ->assertStatus(405)
        ->assertHeader('Allow');
});

it('deletes a subject that no class offers', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $subject = catalogSubject();

    withToken($token)->deleteJson("/api/v1/subjects/{$subject->id}")->assertOk();

    expect(Subject::query()->whereKey($subject->id)->exists())->toBeFalse();
});

it('refuses to delete a subject that a class still offers', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $classSubject = activeClassSubject();
    $subject = $classSubject->subject;

    withToken($token)->deleteJson("/api/v1/subjects/{$subject->id}")->assertStatus(422);

    expect(Subject::query()->whereKey($subject->id)->exists())->toBeTrue();
});

it('refuses to delete a subject even if its only offering has been deactivated', function (): void {
    // Deliberately checks ANY class_subjects row, not only ACTIVE ones - see
    // SubjectService::deleteSubject(). An INACTIVE offering is still a historical fact.
    $token = loginAs(userWithRole(Role::ADMIN));
    $classSubject = activeClassSubject(['status' => CatalogStatus::INACTIVE]);
    $subject = $classSubject->subject;

    withToken($token)->deleteJson("/api/v1/subjects/{$subject->id}")->assertStatus(422);

    expect(Subject::query()->whereKey($subject->id)->exists())->toBeTrue();
});

it('has both a view/create/update/delete permission set for subjects', function (): void {
    expect(SubjectPermissionSeeder::names())->toContain(
        'subjects.view', 'subjects.create', 'subjects.update', 'subjects.delete',
    );
});

/*
|--------------------------------------------------------------------------
| Cross-entity edge cases: a subject's status and its class offerings are
| independent facts, and a class subject survives its class being retired
|--------------------------------------------------------------------------
*/

it('deactivating a subject does not touch its existing class offerings', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $classSubject = activeClassSubject();
    $subject = $classSubject->subject;

    withToken($token)->putJson("/api/v1/subjects/{$subject->id}", subjectUpdatePayload([
        'name' => $subject->name,
        'code' => $subject->code,
        'status' => 'INACTIVE',
    ]))->assertOk();

    // The subject's own catalogue status and a class's offering of it are two different
    // facts - deactivating one must not silently deactivate the other, the identical
    // separation Module 04 and Module 03 each enforce between a person's roll/employment
    // status and their account status.
    expect($subject->refresh()->status)->toBe(CatalogStatus::INACTIVE)
        ->and($classSubject->refresh()->status)->toBe(CatalogStatus::ACTIVE);
});

it('keeps an existing class subject readable and amendable after its class is later retired', function (): void {
    // A class subject's own creation-time check refuses a RETIRED class - see
    // ClassSubjectManagementTest - but a class that was ACTIVE when the offering was made and
    // is retired afterwards must not corrupt or hide the historical offering, the same
    // principle Module 06 applies to an enrollment whose session later completes.
    $admin = userWithRole(Role::ADMIN);
    $classSubject = activeClassSubject();

    withToken(loginAs($admin))->putJson("/api/v1/classes/{$classSubject->school_class_id}", [
        'name' => $classSubject->schoolClass->name,
        'code' => $classSubject->schoolClass->code,
        'class_level_id' => $classSubject->schoolClass->class_level_id,
        'status' => 'ARCHIVED',
    ])->assertOk();

    $registrarToken = loginAs(userWithRole(Role::REGISTRAR));

    withToken($registrarToken)->getJson("/api/v1/class-subjects/{$classSubject->id}")
        ->assertOk()
        ->assertJsonPath('data.school_class.status', 'ARCHIVED');

    withToken($registrarToken)->putJson("/api/v1/class-subjects/{$classSubject->id}", ['status' => 'INACTIVE'])
        ->assertOk()
        ->assertJsonPath('data.status', 'INACTIVE');
});

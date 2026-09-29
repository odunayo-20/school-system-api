<?php

use App\Enums\CatalogStatus;
use App\Enums\Role;
use App\Models\AssessmentType;
use Database\Seeders\AssessmentPermissionSeeder;

/*
|--------------------------------------------------------------------------
| Assessment type catalogue: create, read, amend, delete (Module 09)
|--------------------------------------------------------------------------
*/

it('adds a category to the assessment type catalogue', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    $response = withToken($token)->postJson('/api/v1/assessment-types', assessmentTypeCreatePayload([
        'name' => 'Continuous Assessment',
        'code' => 'ca',
    ]));

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Continuous Assessment')
        ->assertJsonPath('data.code', 'CA')
        ->assertJsonPath('data.status', CatalogStatus::ACTIVE->value);

    expect(AssessmentType::query()->count())->toBe(1);
});

it('requires a name', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    $payload = assessmentTypeCreatePayload();
    unset($payload['name']);

    withToken($token)->postJson('/api/v1/assessment-types', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');

    expect(AssessmentType::query()->count())->toBe(0);
});

it('requires a code', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    $payload = assessmentTypeCreatePayload();
    unset($payload['code']);

    withToken($token)->postJson('/api/v1/assessment-types', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('code');
});

it('refuses a duplicate name', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    catalogAssessmentType(['name' => 'Examination']);

    withToken($token)->postJson('/api/v1/assessment-types', assessmentTypeCreatePayload(['name' => 'Examination']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');

    expect(AssessmentType::query()->count())->toBe(1);
});

it('refuses a duplicate code', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    catalogAssessmentType(['code' => 'EXM']);

    withToken($token)->postJson('/api/v1/assessment-types', assessmentTypeCreatePayload(['code' => 'EXM']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('code');

    expect(AssessmentType::query()->count())->toBe(1);
});

it('treats two codes with different casing as the same code', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    withToken($token)->postJson('/api/v1/assessment-types', assessmentTypeCreatePayload(['code' => 'ca']))
        ->assertCreated()
        ->assertJsonPath('data.code', 'CA');

    withToken($token)->postJson('/api/v1/assessment-types', assessmentTypeCreatePayload(['code' => 'CA']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('code');

    expect(AssessmentType::query()->count())->toBe(1);
});

it('rejects a code longer than the maximum length', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    withToken($token)->postJson('/api/v1/assessment-types', assessmentTypeCreatePayload(['code' => str_repeat('A', 21)]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('code');
});

it('rejects an invalid status', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    withToken($token)->postJson('/api/v1/assessment-types', assessmentTypeCreatePayload(['status' => 'DRAFT']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');
});

it('shows a single assessment type', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $assessmentType = catalogAssessmentType(['name' => 'Test', 'code' => 'TST']);

    withToken($token)->getJson("/api/v1/assessment-types/{$assessmentType->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $assessmentType->id)
        ->assertJsonPath('data.name', 'Test');
});

it('answers 404 for an assessment type that does not exist', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    withToken($token)->getJson('/api/v1/assessment-types/999999')->assertNotFound();
});

it('lists assessment types ordered by sort order then name', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    AssessmentType::factory()->create(['name' => 'Test', 'sort_order' => 1]);
    AssessmentType::factory()->create(['name' => 'CA', 'sort_order' => 1]);
    AssessmentType::factory()->create(['name' => 'Examination', 'sort_order' => 2]);

    $response = withToken($token)->getJson('/api/v1/assessment-types');

    $response->assertOk();

    expect(array_column($response->json('data'), 'name'))->toBe(['CA', 'Test', 'Examination']);
});

it('amends an assessment type', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $assessmentType = catalogAssessmentType(['name' => 'CA', 'code' => 'CA']);

    withToken($token)->putJson("/api/v1/assessment-types/{$assessmentType->id}", assessmentTypeUpdatePayload([
        'name' => 'Continuous Assessment',
        'code' => 'CA',
    ]))->assertOk()->assertJsonPath('data.name', 'Continuous Assessment');

    expect($assessmentType->refresh()->name)->toBe('Continuous Assessment');
});

it('allows updating an assessment type without changing its own unique name or code', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $assessmentType = catalogAssessmentType(['name' => 'CA', 'code' => 'CA', 'sort_order' => 1]);

    withToken($token)->putJson("/api/v1/assessment-types/{$assessmentType->id}", [
        'name' => 'CA',
        'code' => 'CA',
        'sort_order' => 5,
    ])->assertOk()->assertJsonPath('data.sort_order', 5);
});

it('refuses to amend an assessment type to a code already used by another', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    catalogAssessmentType(['code' => 'TST']);
    $assessmentType = catalogAssessmentType(['code' => 'CA']);

    withToken($token)->putJson("/api/v1/assessment-types/{$assessmentType->id}", assessmentTypeUpdatePayload(['code' => 'TST']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('code');

    expect($assessmentType->refresh()->code)->toBe('CA');
});

it('changes an assessment type status to inactive and back', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $assessmentType = catalogAssessmentType();

    withToken($token)->putJson("/api/v1/assessment-types/{$assessmentType->id}", assessmentTypeUpdatePayload([
        'name' => $assessmentType->name,
        'code' => $assessmentType->code,
        'status' => 'INACTIVE',
    ]))->assertOk()->assertJsonPath('data.status', 'INACTIVE');

    withToken($token)->putJson("/api/v1/assessment-types/{$assessmentType->id}", assessmentTypeUpdatePayload([
        'name' => $assessmentType->name,
        'code' => $assessmentType->code,
        'status' => 'ACTIVE',
    ]))->assertOk()->assertJsonPath('data.status', 'ACTIVE');
});

it('answers 405 for a PATCH', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $assessmentType = catalogAssessmentType();

    withToken($token)->patchJson("/api/v1/assessment-types/{$assessmentType->id}", assessmentTypeUpdatePayload())
        ->assertStatus(405)
        ->assertHeader('Allow');
});

it('deletes an assessment type that no assessment uses', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $assessmentType = catalogAssessmentType();

    withToken($token)->deleteJson("/api/v1/assessment-types/{$assessmentType->id}")->assertOk();

    expect(AssessmentType::query()->whereKey($assessmentType->id)->exists())->toBeFalse();
});

it('refuses to delete an assessment type that an assessment still uses', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $assessment = activeAssessment();
    $assessmentType = $assessment->assessmentType;

    withToken($token)->deleteJson("/api/v1/assessment-types/{$assessmentType->id}")->assertStatus(422);

    expect(AssessmentType::query()->whereKey($assessmentType->id)->exists())->toBeTrue();
});

it('refuses to delete an assessment type even if its only assessment has been retired', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $assessment = activeAssessment(['status' => CatalogStatus::INACTIVE]);
    $assessmentType = $assessment->assessmentType;

    withToken($token)->deleteJson("/api/v1/assessment-types/{$assessmentType->id}")->assertStatus(422);

    expect(AssessmentType::query()->whereKey($assessmentType->id)->exists())->toBeTrue();
});

it('has both a view/create/update/delete permission set for assessment types', function (): void {
    expect(AssessmentPermissionSeeder::names())->toContain(
        'assessment_types.view', 'assessment_types.create', 'assessment_types.update', 'assessment_types.delete',
    );
});

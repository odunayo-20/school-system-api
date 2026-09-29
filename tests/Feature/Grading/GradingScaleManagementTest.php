<?php

use App\Enums\CatalogStatus;
use App\Enums\Role;
use App\Models\ClassLevel;
use App\Models\GradingScale;
use Database\Seeders\GradingPermissionSeeder;

/*
|--------------------------------------------------------------------------
| Grading scale: create, read, amend (Module 11)
|--------------------------------------------------------------------------
*/

it('configures a grading scale with its bands', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $level = ClassLevel::factory()->create();

    $response = withToken($token)->postJson('/api/v1/grading-scales', gradingScaleCreatePayload([
        'class_level_id' => $level->id,
        'name' => 'Junior Secondary Standard',
        'code' => 'jss-std',
    ]));

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Junior Secondary Standard')
        ->assertJsonPath('data.code', 'JSS-STD')
        ->assertJsonPath('data.status', CatalogStatus::ACTIVE->value)
        ->assertJsonPath('data.class_level.id', $level->id);

    expect(GradingScale::query()->count())->toBe(1);
});

it('requires a class level', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    $payload = gradingScaleCreatePayload();
    unset($payload['class_level_id']);

    withToken($token)->postJson('/api/v1/grading-scales', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('class_level_id');
});

it('refuses a class level that does not exist', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    withToken($token)->postJson('/api/v1/grading-scales', gradingScaleCreatePayload(['class_level_id' => 999999]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('class_level_id');
});

it('refuses a class level that has been retired', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $retiredLevel = ClassLevel::factory()->status(CatalogStatus::ARCHIVED)->create();

    withToken($token)->postJson('/api/v1/grading-scales', gradingScaleCreatePayload(['class_level_id' => $retiredLevel->id]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('class_level_id');
});

it('requires a name', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    $payload = gradingScaleCreatePayload();
    unset($payload['name']);

    withToken($token)->postJson('/api/v1/grading-scales', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');
});

it('requires a code', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    $payload = gradingScaleCreatePayload();
    unset($payload['code']);

    withToken($token)->postJson('/api/v1/grading-scales', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('code');
});

it('allows two different class levels to each have a scale with the same name', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $levelA = ClassLevel::factory()->create();
    $levelB = ClassLevel::factory()->create();

    withToken($token)->postJson('/api/v1/grading-scales', gradingScaleCreatePayload([
        'class_level_id' => $levelA->id,
        'name' => 'Standard',
        'code' => 'STDA',
    ]))->assertCreated();

    withToken($token)->postJson('/api/v1/grading-scales', gradingScaleCreatePayload([
        'class_level_id' => $levelB->id,
        'name' => 'Standard',
        'code' => 'STDB',
    ]))->assertCreated();

    expect(GradingScale::query()->where('name', 'Standard')->count())->toBe(2);
});

it('refuses a duplicate name within the same class level', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $level = ClassLevel::factory()->create();
    configuredGradingScale(['class_level_id' => $level->id, 'name' => 'Standard']);

    withToken($token)->postJson('/api/v1/grading-scales', gradingScaleCreatePayload([
        'class_level_id' => $level->id,
        'name' => 'Standard',
    ]))->assertUnprocessable()->assertJsonValidationErrors('name');
});

it('refuses a second active scale for the same class level', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $level = ClassLevel::factory()->create();
    configuredGradingScale(['class_level_id' => $level->id]);

    $response = withToken($token)->postJson('/api/v1/grading-scales', gradingScaleCreatePayload([
        'class_level_id' => $level->id,
    ]));

    $response->assertUnprocessable();

    expect(GradingScale::query()->where('class_level_id', $level->id)->count())->toBe(1);
});

/*
| Item validation: boundaries, overlap, gaps, duplicate grades
*/

it('rejects a band whose minimum exceeds its maximum', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    withToken($token)->postJson('/api/v1/grading-scales', gradingScaleCreatePayload([
        'items' => [
            ['grade' => 'A', 'min_percentage' => 80, 'max_percentage' => 70, 'grade_point' => 5, 'remark' => null],
        ],
    ]))->assertUnprocessable()->assertJsonValidationErrors('items.0.min_percentage');
});

it('rejects a negative minimum percentage', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    withToken($token)->postJson('/api/v1/grading-scales', gradingScaleCreatePayload([
        'items' => [
            ['grade' => 'A', 'min_percentage' => -1, 'max_percentage' => 100, 'grade_point' => 5, 'remark' => null],
        ],
    ]))->assertUnprocessable()->assertJsonValidationErrors('items.0.min_percentage');
});

it('rejects a maximum percentage above 100', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    withToken($token)->postJson('/api/v1/grading-scales', gradingScaleCreatePayload([
        'items' => [
            ['grade' => 'A', 'min_percentage' => 90, 'max_percentage' => 100.5, 'grade_point' => 5, 'remark' => null],
        ],
    ]))->assertUnprocessable()->assertJsonValidationErrors('items.0.max_percentage');
});

it('rejects overlapping bands', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    $response = withToken($token)->postJson('/api/v1/grading-scales', gradingScaleCreatePayload([
        'items' => [
            ['grade' => 'A', 'min_percentage' => 70, 'max_percentage' => 100, 'grade_point' => 5, 'remark' => null],
            ['grade' => 'B', 'min_percentage' => 60, 'max_percentage' => 80, 'grade_point' => 4, 'remark' => null],
        ],
    ]));

    $response->assertUnprocessable()->assertJsonValidationErrors('items.0.min_percentage');

    expect(GradingScale::query()->count())->toBe(0);
});

it('rejects bands that overlap at a single shared boundary point', function (): void {
    // [60,70] and [70,100] both cover 70.00 - inclusive-inclusive means this IS an overlap,
    // not a valid adjacency. The valid adjacency is 60-69.99 / 70-100.
    $token = loginAs(userWithRole(Role::ADMIN));

    withToken($token)->postJson('/api/v1/grading-scales', gradingScaleCreatePayload([
        'items' => [
            ['grade' => 'A', 'min_percentage' => 70, 'max_percentage' => 100, 'grade_point' => 5, 'remark' => null],
            ['grade' => 'B', 'min_percentage' => 60, 'max_percentage' => 70, 'grade_point' => 4, 'remark' => null],
        ],
    ]))->assertUnprocessable()->assertJsonValidationErrors('items.0.min_percentage');
});

it('accepts adjacent bands that meet at the smallest representable boundary', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    withToken($token)->postJson('/api/v1/grading-scales', gradingScaleCreatePayload([
        'items' => [
            ['grade' => 'A', 'min_percentage' => 70, 'max_percentage' => 100, 'grade_point' => 5, 'remark' => null],
            ['grade' => 'B', 'min_percentage' => 60, 'max_percentage' => 69.99, 'grade_point' => 4, 'remark' => null],
        ],
    ]))->assertCreated();
});

it('allows a gap between bands', function (): void {
    // 50-59.99 is left uncovered on purpose - a legitimate configuration, not silently
    // rejected. See GradingCalculationTest for how an uncovered percentage is reported.
    $token = loginAs(userWithRole(Role::ADMIN));

    withToken($token)->postJson('/api/v1/grading-scales', gradingScaleCreatePayload([
        'items' => [
            ['grade' => 'A', 'min_percentage' => 70, 'max_percentage' => 100, 'grade_point' => 5, 'remark' => null],
            ['grade' => 'F', 'min_percentage' => 0, 'max_percentage' => 49.99, 'grade_point' => 0, 'remark' => null],
        ],
    ]))->assertCreated();
});

it('rejects a duplicate grade within the same scale', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    $response = withToken($token)->postJson('/api/v1/grading-scales', gradingScaleCreatePayload([
        'items' => [
            ['grade' => 'A', 'min_percentage' => 70, 'max_percentage' => 100, 'grade_point' => 5, 'remark' => null],
            ['grade' => 'A', 'min_percentage' => 0, 'max_percentage' => 69.99, 'grade_point' => 4, 'remark' => null],
        ],
    ]));

    $response->assertUnprocessable()->assertJsonValidationErrors('items.1.grade');

    expect(GradingScale::query()->count())->toBe(0);
});

it('requires at least one band', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    withToken($token)->postJson('/api/v1/grading-scales', gradingScaleCreatePayload(['items' => []]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('items');
});

it('accepts a decimal grade point', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    $response = withToken($token)->postJson('/api/v1/grading-scales', gradingScaleCreatePayload([
        'items' => [
            ['grade' => 'A', 'min_percentage' => 0, 'max_percentage' => 100, 'grade_point' => 4.5, 'remark' => 'Excellent'],
        ],
    ]));

    $response->assertCreated()->assertJsonPath('data.items.0.grade_point', '4.50');
});

/*
| Read, amend, lifecycle, delete
*/

it('shows a single grading scale with its bands', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $scale = configuredGradingScale();

    withToken($token)->getJson("/api/v1/grading-scales/{$scale->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $scale->id)
        ->assertJsonCount(5, 'data.items');
});

it('answers 404 for a grading scale that does not exist', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    withToken($token)->getJson('/api/v1/grading-scales/999999')->assertNotFound();
});

it('does not load bands on the list endpoint', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    configuredGradingScale();

    $response = withToken($token)->getJson('/api/v1/grading-scales')->assertOk();

    expect($response->json('data.0'))->not->toHaveKey('items');
});

it('amends a grading scale, replacing its bands as a whole', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $scale = configuredGradingScale();

    $response = withToken($token)->putJson("/api/v1/grading-scales/{$scale->id}", gradingScaleUpdatePayload([
        'name' => 'Revised Standard',
        'items' => [
            ['grade' => 'PASS', 'min_percentage' => 50, 'max_percentage' => 100, 'grade_point' => 1, 'remark' => 'Pass'],
            ['grade' => 'FAIL', 'min_percentage' => 0, 'max_percentage' => 49.99, 'grade_point' => 0, 'remark' => 'Fail'],
        ],
    ]));

    $response->assertOk()
        ->assertJsonPath('data.name', 'Revised Standard')
        ->assertJsonCount(2, 'data.items');

    expect($scale->items()->count())->toBe(2)
        ->and($scale->items()->where('grade', 'A')->exists())->toBeFalse();
});

it('treats the amend as a whole record write, so omitting items is refused', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $scale = configuredGradingScale();

    withToken($token)->putJson("/api/v1/grading-scales/{$scale->id}", [
        'name' => $scale->name,
        'code' => $scale->code,
    ])->assertUnprocessable()->assertJsonValidationErrors('items');
});

it('cannot reach class_level_id through the amend endpoint', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $scale = configuredGradingScale();
    $otherLevel = ClassLevel::factory()->create();

    withToken($token)->putJson("/api/v1/grading-scales/{$scale->id}", array_merge(gradingScaleUpdatePayload(), [
        'class_level_id' => $otherLevel->id,
    ]))->assertOk();

    expect($scale->refresh()->class_level_id)->not->toBe($otherLevel->id);
});

it('changes a grading scale status to inactive and back', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $scale = configuredGradingScale();

    withToken($token)->putJson("/api/v1/grading-scales/{$scale->id}", gradingScaleUpdatePayload([
        'name' => $scale->name,
        'code' => $scale->code,
        'status' => 'INACTIVE',
    ]))->assertOk()->assertJsonPath('data.status', 'INACTIVE');

    withToken($token)->putJson("/api/v1/grading-scales/{$scale->id}", gradingScaleUpdatePayload([
        'name' => $scale->name,
        'code' => $scale->code,
        'status' => 'ACTIVE',
    ]))->assertOk()->assertJsonPath('data.status', 'ACTIVE');
});

it('refuses reactivating a scale while another is already active for the same class level', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $level = ClassLevel::factory()->create();
    $active = configuredGradingScale(['class_level_id' => $level->id]);
    $inactive = configuredGradingScale(['class_level_id' => $level->id, 'status' => CatalogStatus::INACTIVE]);

    withToken($token)->putJson("/api/v1/grading-scales/{$inactive->id}", gradingScaleUpdatePayload([
        'name' => $inactive->name,
        'code' => $inactive->code,
        'status' => 'ACTIVE',
    ]))->assertUnprocessable()->assertJsonValidationErrors('status');

    expect($active->refresh()->status)->toBe(CatalogStatus::ACTIVE)
        ->and($inactive->refresh()->status)->toBe(CatalogStatus::INACTIVE);
});

it('answers 405 for a PATCH', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $scale = configuredGradingScale();

    withToken($token)->patchJson("/api/v1/grading-scales/{$scale->id}", gradingScaleUpdatePayload())
        ->assertStatus(405)
        ->assertHeader('Allow');
});

it('refuses a delete outright, because a grading scale is the anchor for future result records', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $scale = configuredGradingScale();

    withToken($token)->deleteJson("/api/v1/grading-scales/{$scale->id}")->assertStatus(405);

    expect(GradingScale::query()->whereKey($scale->id)->exists())->toBeTrue();
});

it('has no delete permission to grant in the first place', function (): void {
    expect(GradingPermissionSeeder::names())
        ->toContain('grading_scales.view', 'grading_scales.create', 'grading_scales.update')
        ->not->toContain('grading_scales.delete');
});

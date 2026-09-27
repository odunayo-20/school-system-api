<?php

use App\Enums\CatalogStatus;
use App\Enums\Role;
use App\Models\ClassLevel;
use App\Models\SchoolClass;

test('listing class levels requires authentication', function () {
    $this->getJson('/api/v1/class-levels')->assertUnauthorized();
});

test('listing class levels requires the view permission', function () {
    asUser(userWithRole(Role::STUDENT))
        ->getJson('/api/v1/class-levels')
        ->assertForbidden();
});

test('a class level is created active with its code folded to upper case', function () {
    asUser(userWithRole(Role::REGISTRAR))
        ->postJson('/api/v1/class-levels', [
            'name' => 'Primary',
            'code' => 'pri',
            'sort_order' => 2,
        ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Primary')
        ->assertJsonPath('data.code', 'PRI')
        ->assertJsonPath('data.sort_order', 2)
        ->assertJsonPath('data.status', CatalogStatus::ACTIVE->value);

    $this->assertDatabaseHas('class_levels', ['name' => 'Primary', 'code' => 'PRI']);
});

test('a class level code is normalised before uniqueness is checked', function () {
    ClassLevel::factory()->create(['name' => 'Primary', 'code' => 'PRI']);

    // The stored code is upper case. If the fold happened after the check, this would pass
    // validation and then fail on the unique index, reporting a client mistake as a 500.
    asUser(userWithRole(Role::REGISTRAR))
        ->postJson('/api/v1/class-levels', [
            'name' => 'Primary One',
            'code' => ' pri ',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);

    expect(ClassLevel::count())->toBe(1);
});

test('a class level name cannot be reused', function () {
    ClassLevel::factory()->create(['name' => 'Primary']);

    asUser(userWithRole(Role::REGISTRAR))
        ->postJson('/api/v1/class-levels', [
            'name' => 'Primary',
            'code' => 'P2',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

test('a class level name is unique across the school rather than within a parent', function () {
    // A level has no parent, so the same name cannot appear twice at all. Contrast with a
    // class, whose name is unique only inside its level.
    ClassLevel::factory()->create(['name' => 'Primary', 'code' => 'P1']);

    asUser(userWithRole(Role::REGISTRAR))
        ->postJson('/api/v1/class-levels', [
            'name' => 'Primary',
            'code' => 'P2',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

test('an unknown status is rejected', function () {
    asUser(userWithRole(Role::REGISTRAR))
        ->postJson('/api/v1/class-levels', [
            'name' => 'Primary',
            'code' => 'PRI',
            'status' => 'RETIRED',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);
});

test('class levels are listed in display order', function () {
    ClassLevel::factory()->create(['name' => 'Junior Secondary', 'sort_order' => 3]);
    ClassLevel::factory()->create(['name' => 'Nursery', 'sort_order' => 1]);
    ClassLevel::factory()->create(['name' => 'Primary', 'sort_order' => 2]);

    asUser(userWithRole(Role::STAFF))
        ->getJson('/api/v1/class-levels')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.name', 'Nursery')
        ->assertJsonPath('data.1.name', 'Primary')
        ->assertJsonPath('data.2.name', 'Junior Secondary');
});

test('class levels can be filtered by status and searched', function () {
    ClassLevel::factory()->create(['name' => 'Primary', 'status' => CatalogStatus::ACTIVE]);
    ClassLevel::factory()->create(['name' => 'Secondary', 'status' => CatalogStatus::ARCHIVED]);

    asUser(userWithRole(Role::STAFF))
        ->getJson('/api/v1/class-levels?status=ARCHIVED')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Secondary');

    asUser(userWithRole(Role::STAFF))
        ->getJson('/api/v1/class-levels?search=prim')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Primary');
});

test('active only offers just the levels a class could be created in', function () {
    ClassLevel::factory()->create(['name' => 'Primary', 'status' => CatalogStatus::ACTIVE]);
    ClassLevel::factory()->create(['name' => 'Secondary', 'status' => CatalogStatus::ARCHIVED]);
    ClassLevel::factory()->create(['name' => 'Upper Secondary', 'status' => CatalogStatus::INACTIVE]);

    // This is what backs a "choose a class level" picker. A retired level has to be absent,
    // because a class created in one would be structure that no picker would ever offer.
    asUser(userWithRole(Role::STAFF))
        ->getJson('/api/v1/class-levels?active_only=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Primary');
});

test('a class level is readable with how many classes it holds', function () {
    $level = ClassLevel::factory()->create();
    SchoolClass::factory()->count(2)->create(['class_level_id' => $level->getKey()]);

    asUser(userWithRole(Role::STAFF))
        ->getJson("/api/v1/class-levels/{$level->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $level->id)
        ->assertJsonPath('data.classes_count', 2);
});

test('a class level can be renamed and reordered', function () {
    $level = ClassLevel::factory()->create(['name' => 'Primary', 'sort_order' => 2]);

    asUser(userWithRole(Role::REGISTRAR))
        ->patchJson("/api/v1/class-levels/{$level->id}", [
            'name' => 'Lower Primary',
            'code' => $level->code,
            'sort_order' => 1,
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Lower Primary')
        ->assertJsonPath('data.sort_order', 1);
});

test('a class level can be re-saved with its own name and code', function () {
    $level = ClassLevel::factory()->create(['name' => 'Primary', 'code' => 'PRI']);

    asUser(userWithRole(Role::REGISTRAR))
        ->patchJson("/api/v1/class-levels/{$level->id}", [
            'name' => 'Primary',
            'code' => 'PRI',
        ])
        ->assertOk();
});

test('a class level is retired through the ordinary update', function () {
    $level = ClassLevel::factory()->create(['name' => 'Primary', 'code' => 'PRI']);

    // There is no archive endpoint: retiring is a status value, and a second way to set it
    // would leave two code paths for one meaning.
    asUser(userWithRole(Role::REGISTRAR))
        ->patchJson("/api/v1/class-levels/{$level->id}", [
            'name' => 'Primary',
            'code' => 'PRI',
            'status' => CatalogStatus::ARCHIVED->value,
        ])
        ->assertOk()
        ->assertJsonPath('data.status', CatalogStatus::ARCHIVED->value);

    expect($level->fresh()->status)->toBe(CatalogStatus::ARCHIVED);
});

test('an empty class level can be deleted', function () {
    $level = ClassLevel::factory()->create();

    asUser(userWithRole(Role::ADMIN))
        ->deleteJson("/api/v1/class-levels/{$level->id}")
        ->assertOk();

    $this->assertDatabaseMissing('class_levels', ['id' => $level->id]);
});

test('a class level that still has classes cannot be deleted', function () {
    $level = ClassLevel::factory()->create();
    SchoolClass::factory()->create(['class_level_id' => $level->getKey()]);

    asUser(userWithRole(Role::ADMIN))
        ->deleteJson("/api/v1/class-levels/{$level->id}")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'This class level still has classes. Move or delete them first.');

    $this->assertDatabaseHas('class_levels', ['id' => $level->id]);
});

test('a registrar may not delete a class level', function () {
    $level = ClassLevel::factory()->create();

    asUser(userWithRole(Role::REGISTRAR))
        ->deleteJson("/api/v1/class-levels/{$level->id}")
        ->assertForbidden();
});

test('a missing class level returns a json 404', function () {
    asUser(userWithRole(Role::STAFF))
        ->getJson('/api/v1/class-levels/999999')
        ->assertNotFound()
        ->assertJsonPath('message', 'Resource not found.');
});

test('class levels are paginated with the metadata beside the data', function () {
    ClassLevel::factory()->count(4)->create();

    asUser(userWithRole(Role::STAFF))
        ->getJson('/api/v1/class-levels?per_page=2')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonStructure([
            'data',
            'links' => ['first', 'last', 'prev', 'next'],
            'meta' => ['current_page', 'last_page', 'per_page', 'from', 'to', 'total'],
        ])
        ->assertJsonPath('meta.total', 4)
        ->assertJsonMissingPath('data.data');
});

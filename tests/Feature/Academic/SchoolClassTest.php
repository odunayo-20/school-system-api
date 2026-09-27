<?php

use App\Enums\CatalogStatus;
use App\Enums\Role;
use App\Models\ClassLevel;
use App\Models\SchoolClass;
use App\Models\Section;

test('listing classes requires authentication', function () {
    $this->getJson('/api/v1/classes')->assertUnauthorized();
});

test('listing classes requires the view permission', function () {
    asUser(userWithRole(Role::STUDENT))
        ->getJson('/api/v1/classes')
        ->assertForbidden();
});

test('a class is created inside a level with its code folded to upper case', function () {
    $level = ClassLevel::factory()->create(['name' => 'Primary']);

    asUser(userWithRole(Role::REGISTRAR))
        ->postJson('/api/v1/classes', [
            'class_level_id' => $level->id,
            'name' => 'Primary 1',
            'code' => 'p1',
            'sort_order' => 1,
        ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Primary 1')
        ->assertJsonPath('data.code', 'P1')
        ->assertJsonPath('data.class_level_id', $level->id)
        ->assertJsonPath('data.status', CatalogStatus::ACTIVE->value)
        ->assertJsonPath('data.class_level.name', 'Primary');
});

test('a class cannot be created in a level that is not active', function () {
    $archived = ClassLevel::factory()->status(CatalogStatus::ARCHIVED)->create();
    $inactive = ClassLevel::factory()->status(CatalogStatus::INACTIVE)->create();

    // A class inside a retired level would be structure that no picker offers, so it could
    // never be seen or amended by anyone.
    asUser(userWithRole(Role::REGISTRAR))
        ->postJson('/api/v1/classes', [
            'class_level_id' => $archived->id,
            'name' => 'Primary 1',
            'code' => 'P1',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['class_level_id']);

    asUser(userWithRole(Role::REGISTRAR))
        ->postJson('/api/v1/classes', [
            'class_level_id' => $inactive->id,
            'name' => 'Primary 2',
            'code' => 'P2',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['class_level_id']);

    expect(SchoolClass::count())->toBe(0);
});

test('a class cannot be created in a level that does not exist', function () {
    asUser(userWithRole(Role::REGISTRAR))
        ->postJson('/api/v1/classes', [
            'class_level_id' => 999999,
            'name' => 'Primary 1',
            'code' => 'P1',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['class_level_id']);
});

test('a class name and code are unique inside their level only', function () {
    $primary = ClassLevel::factory()->create();
    $secondary = ClassLevel::factory()->create();

    SchoolClass::factory()->within($primary, 'Primary 1', 'P1')->create();

    // "Primary 1" in Primary and "Primary 1" in Secondary are different rows in different
    // years of schooling, so the collision check is scoped rather than global.
    asUser(userWithRole(Role::REGISTRAR))
        ->postJson('/api/v1/classes', [
            'class_level_id' => $primary->id,
            'name' => 'Primary 1',
            'code' => 'P9',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);

    asUser(userWithRole(Role::REGISTRAR))
        ->postJson('/api/v1/classes', [
            'class_level_id' => $secondary->id,
            'name' => 'Primary 1',
            'code' => 'P1',
        ])
        ->assertCreated();
});

test('a class code is normalised before it is checked for uniqueness', function () {
    $level = ClassLevel::factory()->create();
    SchoolClass::factory()->within($level, 'Primary 1', 'P1')->create();

    asUser(userWithRole(Role::REGISTRAR))
        ->postJson('/api/v1/classes', [
            'class_level_id' => $level->id,
            'name' => 'Primary One',
            'code' => ' p1 ',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);
});

test('classes can be filtered to one level and searched', function () {
    $primary = ClassLevel::factory()->create();
    $secondary = ClassLevel::factory()->create();

    SchoolClass::factory()->within($primary, 'Primary 1', 'P1')->create();
    SchoolClass::factory()->within($secondary, 'Secondary 1', 'S1')->create();

    asUser(userWithRole(Role::STAFF))
        ->getJson("/api/v1/classes?class_level_id={$primary->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Primary 1');

    asUser(userWithRole(Role::STAFF))
        ->getJson('/api/v1/classes?search=second')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Secondary 1');
});

test('classes span the whole school by default', function () {
    $one = ClassLevel::factory()->create();
    $two = ClassLevel::factory()->create();

    SchoolClass::factory()->within($one, 'Primary 1', 'P1')->create();
    SchoolClass::factory()->within($two, 'Secondary 1', 'S1')->create();

    // The default view is every class in the school: that is the screen that matters day to
    // day, and per level filtering is a refinement of it.
    asUser(userWithRole(Role::STAFF))
        ->getJson('/api/v1/classes')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('a class is readable with its level and section count', function () {
    $level = ClassLevel::factory()->create();
    $class = SchoolClass::factory()->within($level, 'Primary 1', 'P1')->create();
    Section::factory()->within($class, 'A', 'A')->create();
    Section::factory()->within($class, 'B', 'B')->create();

    asUser(userWithRole(Role::STAFF))
        ->getJson("/api/v1/classes/{$class->id}")
        ->assertOk()
        ->assertJsonPath('data.name', 'Primary 1')
        ->assertJsonPath('data.sections_count', 2)
        ->assertJsonPath('data.class_level.id', $level->id);
});

test('a class can be renamed inside its level', function () {
    $level = ClassLevel::factory()->create();
    $class = SchoolClass::factory()->within($level, 'Primary 1', 'P1')->create();

    asUser(userWithRole(Role::REGISTRAR))
        ->patchJson("/api/v1/classes/{$class->id}", [
            'name' => 'Primary One',
            'code' => 'P1',
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Primary One');
});

test('a class can be re-saved with its own name and code', function () {
    $level = ClassLevel::factory()->create();
    $class = SchoolClass::factory()->within($level, 'Primary 1', 'P1')->create();

    asUser(userWithRole(Role::REGISTRAR))
        ->patchJson("/api/v1/classes/{$class->id}", [
            'name' => 'Primary 1',
            'code' => 'P1',
        ])
        ->assertOk();
});

test('a class can be moved to another level', function () {
    $from = ClassLevel::factory()->create();
    $to = ClassLevel::factory()->create();
    $class = SchoolClass::factory()->within($from, 'Primary 1', 'P1')->create();

    asUser(userWithRole(Role::REGISTRAR))
        ->patchJson("/api/v1/classes/{$class->id}", [
            'class_level_id' => $to->id,
            'name' => 'Primary 1',
            'code' => 'P1',
        ])
        ->assertOk()
        ->assertJsonPath('data.class_level_id', $to->id);
});

test('a class cannot be moved to a level that already holds its name', function () {
    $from = ClassLevel::factory()->create();
    $to = ClassLevel::factory()->create();

    SchoolClass::factory()->within($to, 'Primary 1', 'P1')->create();
    $class = SchoolClass::factory()->within($from, 'Secondary 1', 'S1')->create();

    // The uniqueness scope follows the level the class ENDS UP in, so a move that would
    // collide is refused rather than allowed to fail on the unique index.
    asUser(userWithRole(Role::REGISTRAR))
        ->patchJson("/api/v1/classes/{$class->id}", [
            'class_level_id' => $to->id,
            'name' => 'Primary 1',
            'code' => 'S1',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);

    expect($class->fresh()->class_level_id)->toBe($from->getKey());
});

test('a class cannot be moved to a level that is not active', function () {
    $from = ClassLevel::factory()->create();
    $archived = ClassLevel::factory()->status(CatalogStatus::ARCHIVED)->create();
    $class = SchoolClass::factory()->within($from, 'Primary 1', 'P1')->create();

    asUser(userWithRole(Role::REGISTRAR))
        ->patchJson("/api/v1/classes/{$class->id}", [
            'class_level_id' => $archived->id,
            'name' => 'Primary 1',
            'code' => 'P1',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['class_level_id']);
});

test('a class is retired through the ordinary update', function () {
    $level = ClassLevel::factory()->create();
    $class = SchoolClass::factory()->within($level, 'Primary 1', 'P1')->create();

    asUser(userWithRole(Role::REGISTRAR))
        ->patchJson("/api/v1/classes/{$class->id}", [
            'name' => 'Primary 1',
            'code' => 'P1',
            'status' => CatalogStatus::INACTIVE->value,
        ])
        ->assertOk()
        ->assertJsonPath('data.status', CatalogStatus::INACTIVE->value);
});

test('a class with no sections can be deleted', function () {
    $level = ClassLevel::factory()->create();
    $class = SchoolClass::factory()->within($level, 'Primary 1', 'P1')->create();

    asUser(userWithRole(Role::ADMIN))
        ->deleteJson("/api/v1/classes/{$class->id}")
        ->assertOk();

    $this->assertDatabaseMissing('classes', ['id' => $class->id]);
});

test('a class that still has sections cannot be deleted', function () {
    $level = ClassLevel::factory()->create();
    $class = SchoolClass::factory()->within($level, 'Primary 1', 'P1')->create();
    Section::factory()->within($class, 'A', 'A')->create();

    asUser(userWithRole(Role::ADMIN))
        ->deleteJson("/api/v1/classes/{$class->id}")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'This class still has sections. Move or delete them first.');

    $this->assertDatabaseHas('classes', ['id' => $class->id]);
});

test('a registrar may not delete a class', function () {
    $level = ClassLevel::factory()->create();
    $class = SchoolClass::factory()->within($level, 'Primary 1', 'P1')->create();

    asUser(userWithRole(Role::REGISTRAR))
        ->deleteJson("/api/v1/classes/{$class->id}")
        ->assertForbidden();
});

test('a missing class returns a json 404', function () {
    asUser(userWithRole(Role::STAFF))
        ->getJson('/api/v1/classes/999999')
        ->assertNotFound()
        ->assertJsonPath('message', 'Resource not found.');
});

test('classes are paginated with the metadata beside the data', function () {
    $level = ClassLevel::factory()->create();
    SchoolClass::factory()->count(3)->create(['class_level_id' => $level->getKey()]);

    asUser(userWithRole(Role::STAFF))
        ->getJson('/api/v1/classes?per_page=2')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonStructure([
            'data',
            'links' => ['first', 'last', 'prev', 'next'],
            'meta' => ['current_page', 'last_page', 'per_page', 'from', 'to', 'total'],
        ])
        ->assertJsonPath('meta.total', 3)
        ->assertJsonMissingPath('data.data');
});

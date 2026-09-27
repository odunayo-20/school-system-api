<?php

use App\Enums\CatalogStatus;
use App\Enums\Role;
use App\Models\ClassLevel;
use App\Models\SchoolClass;
use App\Models\Section;

test('listing sections requires authentication', function () {
    $this->getJson('/api/v1/sections')->assertUnauthorized();
});

test('listing sections requires the view permission', function () {
    asUser(userWithRole(Role::STUDENT))
        ->getJson('/api/v1/sections')
        ->assertForbidden();
});

test('a section is created inside a class with its code folded to upper case', function () {
    $class = SchoolClass::factory()->create();

    asUser(userWithRole(Role::REGISTRAR))
        ->postJson('/api/v1/sections', [
            'school_class_id' => $class->id,
            'name' => 'A',
            'code' => 'a',
            'sort_order' => 1,
        ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'A')
        ->assertJsonPath('data.code', 'A')
        ->assertJsonPath('data.school_class_id', $class->id)
        ->assertJsonPath('data.status', CatalogStatus::ACTIVE->value)
        ->assertJsonPath('data.school_class.id', $class->id);
});

test('a section cannot be created in a class that is not active', function () {
    $inactive = SchoolClass::factory()->status(CatalogStatus::INACTIVE)->create();

    asUser(userWithRole(Role::REGISTRAR))
        ->postJson('/api/v1/sections', [
            'school_class_id' => $inactive->id,
            'name' => 'A',
            'code' => 'A',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['school_class_id']);
});

test('a section name and code are unique inside their class only', function () {
    $one = SchoolClass::factory()->within(ClassLevel::factory()->create(), 'Primary 1', 'P1')->create();
    $two = SchoolClass::factory()->within(ClassLevel::factory()->create(), 'Secondary 1', 'S1')->create();

    Section::factory()->within($one, 'A', 'A')->create();

    // "A" in Primary 1 and "A" in Secondary 1 are unrelated rows, so the collision check is
    // scoped to the class rather than applied across the school.
    asUser(userWithRole(Role::REGISTRAR))
        ->postJson('/api/v1/sections', [
            'school_class_id' => $one->id,
            'name' => 'A',
            'code' => 'Z',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);

    asUser(userWithRole(Role::REGISTRAR))
        ->postJson('/api/v1/sections', [
            'school_class_id' => $two->id,
            'name' => 'A',
            'code' => 'A',
        ])
        ->assertCreated();
});

test('a section code is normalised before it is checked for uniqueness', function () {
    $class = SchoolClass::factory()->create();
    Section::factory()->within($class, 'A', 'A')->create();

    asUser(userWithRole(Role::REGISTRAR))
        ->postJson('/api/v1/sections', [
            'school_class_id' => $class->id,
            'name' => 'Alpha',
            'code' => ' a ',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);
});

test('sections can be filtered to one class and searched', function () {
    $one = SchoolClass::factory()->create();
    $two = SchoolClass::factory()->create();

    Section::factory()->within($one, 'A', 'A')->create();
    Section::factory()->within($two, 'B', 'B')->create();

    asUser(userWithRole(Role::STAFF))
        ->getJson("/api/v1/sections?school_class_id={$one->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'A');

    asUser(userWithRole(Role::STAFF))
        ->getJson('/api/v1/sections?search=b')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'B');
});

test('sections span the whole school by default', function () {
    $one = SchoolClass::factory()->create();
    $two = SchoolClass::factory()->create();

    Section::factory()->within($one, 'A', 'A')->create();
    Section::factory()->within($two, 'B', 'B')->create();

    asUser(userWithRole(Role::STAFF))
        ->getJson('/api/v1/sections')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('sections are listed in display order', function () {
    $class = SchoolClass::factory()->create();

    Section::factory()->within($class, 'C', 'C')->create(['sort_order' => 3]);
    Section::factory()->within($class, 'A', 'A')->create(['sort_order' => 1]);
    Section::factory()->within($class, 'B', 'B')->create(['sort_order' => 2]);

    asUser(userWithRole(Role::STAFF))
        ->getJson("/api/v1/sections?school_class_id={$class->id}")
        ->assertOk()
        ->assertJsonPath('data.0.name', 'A')
        ->assertJsonPath('data.1.name', 'B')
        ->assertJsonPath('data.2.name', 'C');
});

test('a section is readable on its own with its class', function () {
    $class = SchoolClass::factory()->create();
    $section = Section::factory()->within($class, 'A', 'A')->create();

    asUser(userWithRole(Role::STAFF))
        ->getJson("/api/v1/sections/{$section->id}")
        ->assertOk()
        ->assertJsonPath('data.name', 'A')
        ->assertJsonPath('data.school_class.id', $class->id);
});

test('a section can be renamed and reordered', function () {
    $class = SchoolClass::factory()->create();
    $section = Section::factory()->within($class, 'A', 'A')->create();

    asUser(userWithRole(Role::REGISTRAR))
        ->patchJson("/api/v1/sections/{$section->id}", [
            'name' => 'Alpha',
            'code' => 'A',
            'sort_order' => 2,
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Alpha')
        ->assertJsonPath('data.sort_order', 2);
});

test('a section can be re-saved with its own name and code', function () {
    $class = SchoolClass::factory()->create();
    $section = Section::factory()->within($class, 'A', 'A')->create();

    asUser(userWithRole(Role::REGISTRAR))
        ->patchJson("/api/v1/sections/{$section->id}", [
            'name' => 'A',
            'code' => 'A',
        ])
        ->assertOk();
});

test('a section can be moved to another class', function () {
    $from = SchoolClass::factory()->create();
    $to = SchoolClass::factory()->create();
    $section = Section::factory()->within($from, 'A', 'A')->create();

    // Unlike a class, a section CAN be moved: a section has no meaning outside the class it
    // splits, but a class with no students yet can legitimately be reorganised.
    asUser(userWithRole(Role::REGISTRAR))
        ->patchJson("/api/v1/sections/{$section->id}", [
            'school_class_id' => $to->id,
            'name' => 'A',
            'code' => 'A',
        ])
        ->assertOk()
        ->assertJsonPath('data.school_class_id', $to->id);
});

test('a section cannot be moved to a class that already holds its name', function () {
    $from = SchoolClass::factory()->create();
    $to = SchoolClass::factory()->create();

    Section::factory()->within($to, 'A', 'A')->create();
    $section = Section::factory()->within($from, 'B', 'B')->create();

    asUser(userWithRole(Role::REGISTRAR))
        ->patchJson("/api/v1/sections/{$section->id}", [
            'school_class_id' => $to->id,
            'name' => 'A',
            'code' => 'B',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);

    expect($section->fresh()->school_class_id)->toBe($from->getKey());
});

test('a section can be deleted even though it belongs to a class with children', function () {
    $class = SchoolClass::factory()->create();
    $section = Section::factory()->within($class, 'A', 'A')->create();

    // A section is the bottom of the hierarchy, so it is the one record here that always
    // has something above it and nothing below: deletion is always safe.
    asUser(userWithRole(Role::ADMIN))
        ->deleteJson("/api/v1/sections/{$section->id}")
        ->assertOk();

    $this->assertDatabaseMissing('sections', ['id' => $section->id]);
    $this->assertDatabaseHas('classes', ['id' => $class->id]);
});

test('a registrar may not delete a section', function () {
    $class = SchoolClass::factory()->create();
    $section = Section::factory()->within($class, 'A', 'A')->create();

    asUser(userWithRole(Role::REGISTRAR))
        ->deleteJson("/api/v1/sections/{$section->id}")
        ->assertForbidden();
});

test('a missing section returns a json 404', function () {
    asUser(userWithRole(Role::STAFF))
        ->getJson('/api/v1/sections/999999')
        ->assertNotFound()
        ->assertJsonPath('message', 'Resource not found.');
});

test('sections are paginated with the metadata beside the data', function () {
    $class = SchoolClass::factory()->create();

    // Built explicitly rather than with count(3): the factory draws section names from
    // A/B/C, so three defaults inside one class would collide on the (class, name) key.
    foreach (['A', 'B', 'C'] as $name) {
        Section::factory()->within($class, $name, $name)->create();
    }

    asUser(userWithRole(Role::STAFF))
        ->getJson('/api/v1/sections?per_page=2')
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

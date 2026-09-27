<?php

use App\Enums\CatalogStatus;
use App\Exceptions\BusinessRuleViolation;
use App\Models\ClassLevel;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Services\Academic\AcademicStructureService;

/*
|--------------------------------------------------------------------------
| Structure service invariants
|--------------------------------------------------------------------------
|
| The requests reject a retired parent with a field error, which is the right shape for a
| caller who typed an id into a form. These tests cover the service directly, because the
| service also states the rule in its own contract and every other caller of it - a command,
| a future import, a test - would otherwise be able to file structure under a parent that
| has been archived. A rule enforced only at the edge stops being true the first time
| something reaches past the edge.
|
*/

beforeEach(function () {
    $this->service = new AcademicStructureService;
});

test('a class cannot be created in a retired class level', function () {
    $level = ClassLevel::factory()->create(['status' => CatalogStatus::ARCHIVED]);

    expect(fn () => $this->service->createClass([
        'class_level_id' => $level->getKey(),
        'name' => 'Primary 1',
        'code' => 'p1',
        'sort_order' => 1,
    ]))->toThrow(BusinessRuleViolation::class, 'does not exist or is no longer in use');

    expect(SchoolClass::count())->toBe(0);
});

test('a class cannot be moved into a retired class level', function () {
    $active = ClassLevel::factory()->create(['status' => CatalogStatus::ACTIVE]);
    $retired = ClassLevel::factory()->create(['status' => CatalogStatus::ARCHIVED]);
    $class = SchoolClass::factory()->create(['class_level_id' => $active->getKey()]);

    expect(fn () => $this->service->updateClass($class, [
        'class_level_id' => $retired->getKey(),
    ]))->toThrow(BusinessRuleViolation::class);

    expect($class->refresh()->class_level_id)->toBe($active->getKey());
});

test('a class can be amended while its own level has since been retired', function () {
    // Archiving a level does not retroactively make everything filed under it immutable, and
    // it does not empty the level. A registrar correcting a typo still has to be able to.
    $level = ClassLevel::factory()->create(['status' => CatalogStatus::ARCHIVED]);
    $class = SchoolClass::factory()->create([
        'class_level_id' => $level->getKey(),
        'name' => 'Primary 1',
    ]);

    $updated = $this->service->updateClass($class, ['name' => 'Primary One']);

    expect($updated->name)->toBe('Primary One');
});

test('a class cannot be created under a class level that does not exist', function () {
    expect(fn () => $this->service->createClass([
        'class_level_id' => 999999,
        'name' => 'Primary 1',
        'code' => 'p1',
    ]))->toThrow(BusinessRuleViolation::class);
});

test('a section cannot be created in a retired class', function () {
    $class = SchoolClass::factory()->create(['status' => CatalogStatus::ARCHIVED]);

    expect(fn () => $this->service->createSection([
        'school_class_id' => $class->getKey(),
        'name' => 'A',
        'code' => 'a',
    ]))->toThrow(BusinessRuleViolation::class, 'does not exist or is no longer in use');

    expect(Section::count())->toBe(0);
});

test('a section cannot be moved into a retired class', function () {
    $active = SchoolClass::factory()->create(['status' => CatalogStatus::ACTIVE]);
    $retired = SchoolClass::factory()->create(['status' => CatalogStatus::ARCHIVED]);
    $section = Section::factory()->create(['school_class_id' => $active->getKey()]);

    expect(fn () => $this->service->updateSection($section, [
        'school_class_id' => $retired->getKey(),
    ]))->toThrow(BusinessRuleViolation::class);

    expect($section->refresh()->school_class_id)->toBe($active->getKey());
});

test('a section can be amended while its own class has since been retired', function () {
    $class = SchoolClass::factory()->create(['status' => CatalogStatus::ARCHIVED]);
    $section = Section::factory()->create([
        'school_class_id' => $class->getKey(),
        'name' => 'A',
    ]);

    $updated = $this->service->updateSection($section, ['name' => 'Amber']);

    expect($updated->name)->toBe('Amber');
});

test('a class and section can still be created under an active parent', function () {
    $level = ClassLevel::factory()->create(['status' => CatalogStatus::ACTIVE]);

    $class = $this->service->createClass([
        'class_level_id' => $level->getKey(),
        'name' => 'Primary 1',
        'code' => 'p1',
    ]);

    $section = $this->service->createSection([
        'school_class_id' => $class->getKey(),
        'name' => 'A',
        'code' => 'a',
    ]);

    expect($class->status)->toBe(CatalogStatus::ACTIVE);
    expect($section->status)->toBe(CatalogStatus::ACTIVE);
});

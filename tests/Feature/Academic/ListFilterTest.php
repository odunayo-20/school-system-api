<?php

use App\Enums\CatalogStatus;
use App\Enums\Role;
use App\Models\ClassLevel;
use App\Models\SchoolClass;
use App\Models\Section;

/*
|--------------------------------------------------------------------------
| List filters
|--------------------------------------------------------------------------
|
| The query string is the one part of this API a client controls without a body, a token and
| a permission, so it gets the same validation as everything else. These tests pin the four
| ways it used to be wrong: an unbounded page size, a 500 from a non-numeric id, a boolean
| filter that filtered when asked not to, and a status typo that looked like empty data.
|
| The active_only case is the subtle one. Query parameters arrive as strings, and
| Builder::when() branches on plain truthiness, so the string "false" is truthy. Passing the
| raw parameter through made ?active_only=false return exactly what ?active_only=true
| returned, which is a filter that cannot be switched off.
|
*/

beforeEach(function () {
    asUser(userWithRole(Role::ADMIN));

    $this->activeLevel = ClassLevel::factory()->create([
        'name' => 'Nursery', 'code' => 'NUR', 'status' => CatalogStatus::ACTIVE,
    ]);
    ClassLevel::factory()->create([
        'name' => 'Retired Level', 'code' => 'RET', 'status' => CatalogStatus::ARCHIVED,
    ]);
    ClassLevel::factory()->create([
        'name' => 'Paused Level', 'code' => 'PAU', 'status' => CatalogStatus::INACTIVE,
    ]);
});

test('active_only=true returns only the active records', function () {
    $this->getJson('/api/v1/class-levels?active_only=true')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Nursery');
});

test('active_only=false returns everything, not only the active records', function () {
    // Regression: the string "false" is truthy, so this used to return the same single row as
    // active_only=true, leaving a client no way to ask for the full list.
    $this->getJson('/api/v1/class-levels?active_only=false')
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

test('active_only=0 also returns everything', function () {
    $this->getJson('/api/v1/class-levels?active_only=0')
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

test('an unusable active_only value is rejected rather than guessed at', function () {
    $this->getJson('/api/v1/class-levels?active_only=maybe')
        ->assertStatus(422)
        ->assertJsonValidationErrors('active_only');
});

test('a page size beyond the cap is rejected', function () {
    // Regression: per_page was passed straight to paginate(), so a client could ask for the
    // entire table in one request and force the server to build it all in memory.
    $this->getJson('/api/v1/class-levels?per_page=1000000')
        ->assertStatus(422)
        ->assertJsonValidationErrors('per_page');
});

test('a page size at the cap is allowed', function () {
    $this->getJson('/api/v1/class-levels?per_page=100')
        ->assertOk()
        ->assertJsonPath('meta.per_page', 100);
});

test('the page size defaults to 15', function () {
    $this->getJson('/api/v1/class-levels')
        ->assertOk()
        ->assertJsonPath('meta.per_page', 15);
});

test('a page size of zero or a negative size is rejected', function () {
    $this->getJson('/api/v1/class-levels?per_page=0')->assertStatus(422)->assertJsonValidationErrors('per_page');
    $this->getJson('/api/v1/class-levels?per_page=-5')->assertStatus(422)->assertJsonValidationErrors('per_page');
    $this->getJson('/api/v1/class-levels?per_page=abc')->assertStatus(422)->assertJsonValidationErrors('per_page');
});

test('a non numeric parent id is a validation error rather than a server error', function () {
    // Regression: the raw value reached a closure typed `int $levelId`, and a non numeric
    // string raised a TypeError, so a malformed query string answered 500.
    $this->getJson('/api/v1/classes?class_level_id=abc')
        ->assertStatus(422)
        ->assertJsonValidationErrors('class_level_id');

    $this->getJson('/api/v1/sections?school_class_id=not-a-number')
        ->assertStatus(422)
        ->assertJsonValidationErrors('school_class_id');
});

test('a parent id that does not exist is a validation error', function () {
    $this->getJson('/api/v1/classes?class_level_id=999999')
        ->assertStatus(422)
        ->assertJsonValidationErrors('class_level_id');
});

test('a valid parent id still filters', function () {
    SchoolClass::factory()->create([
        'name' => 'Nursery 1', 'code' => 'N1', 'class_level_id' => $this->activeLevel->getKey(),
    ]);
    SchoolClass::factory()->create([
        'name' => 'Retired 1', 'code' => 'R1', 'class_level_id' => $this->activeLevel->getKey(),
    ]);

    $this->getJson('/api/v1/classes?class_level_id='.$this->activeLevel->getKey())
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('an unknown status names the permitted values instead of returning an empty page', function () {
    // Regression: an unvalidated status silently matched nothing, which reads to a client
    // as "this school has no sessions" rather than "you mistyped the filter".
    $this->getJson('/api/v1/academic-sessions?status=BOGUS')
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');

    $this->getJson('/api/v1/academic-sessions?status=UPCOMING')
        ->assertOk();
});

test('every list endpoint validates its own filters', function () {
    foreach ([
        '/api/v1/academic-sessions?status=NOPE',
        '/api/v1/class-levels?status=NOPE',
        '/api/v1/classes?status=NOPE',
        '/api/v1/sections?status=NOPE',
    ] as $url) {
        $this->getJson($url)->assertStatus(422)->assertJsonValidationErrors('status');
    }

    $this->getJson('/api/v1/academic-sessions?per_page=0')->assertStatus(422);
    $this->getJson('/api/v1/classes?per_page=0')->assertStatus(422);
    $this->getJson('/api/v1/sections?per_page=0')->assertStatus(422);
});

test('search and paging filters are still accepted and echoed back', function () {
    SchoolClass::factory()->create([
        'name' => 'JSS 1', 'code' => 'J1', 'class_level_id' => $this->activeLevel->getKey(),
    ]);
    SchoolClass::factory()->create([
        'name' => 'Primary 5', 'code' => 'P5', 'class_level_id' => $this->activeLevel->getKey(),
    ]);

    $this->getJson('/api/v1/classes?search=jss&per_page=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'JSS 1')
        ->assertJsonPath('meta.per_page', 1)
        ->assertJsonPath('meta.total', 1);
});

test('a section list still reports its parent class', function () {
    $class = SchoolClass::factory()->create([
        'name' => 'JSS 1', 'code' => 'J1', 'class_level_id' => $this->activeLevel->getKey(),
    ]);
    Section::factory()->create(['name' => 'A', 'code' => 'A', 'school_class_id' => $class->getKey()]);

    $this->getJson('/api/v1/sections?school_class_id='.$class->getKey())
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.school_class.name', 'JSS 1');
});

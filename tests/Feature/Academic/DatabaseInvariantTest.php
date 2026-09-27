<?php

use App\Enums\AcademicSessionStatus;
use App\Enums\CatalogStatus;
use App\Enums\SchoolStatus;
use App\Enums\TermStatus;
use App\Models\AcademicSession;
use App\Models\ClassLevel;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Term;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Database invariants
|--------------------------------------------------------------------------
|
| The rules this module relies on are enforced by the database as well as by the model
| events and the services. These tests write through DB::table() and raw SQL, which runs no
| Eloquent events and bypasses every service, to prove the constraints are real.
|
| That distinction matters: an invariant held only by a model hook or a service check can be
| lost to anything that does not go through them - a seeder, a console command, a data
| repair script, a future module, an admin query in a debugging session. A rule that only
| the application enforces is a rule that eventually is not.
|
*/

/*
 * Schools: exactly one row, ever.
 */

test('the database refuses a second school row', function () {
    School::factory()->create(['name' => 'Greenfield School']);

    // No Eloquent event sets singleton_key here, so the row relies on the column default,
    // and the unique index is what has to reject it.
    expect(fn () => DB::table('schools')->insert([
        'name' => 'Second School',
        'short_name' => 'SEC',
        'singleton_key' => 1,
        'status' => 'ACTIVE',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(School::count())->toBe(1);
});

test('raw sql cannot create a second school even without naming the key', function () {
    School::factory()->create();

    // The column defaults to true, so a bare insert never has to know the rule exists.
    expect(fn () => DB::table('schools')->insert([
        'name' => 'Second School',
        'status' => 'ACTIVE',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(School::count())->toBe(1);
});

test('the current school is returned even when it is not active', function () {
    // Status is advisory: it is a fact about the school, not the mechanism that picks
    // which row is THE school. If current() filtered on status, an inactive school would
    // report itself as unconfigured and the PUT endpoint would try to create a second row.
    $school = School::factory()->create(['status' => SchoolStatus::INACTIVE]);

    expect(School::current()?->getKey())->toBe($school->getKey());
});

/*
 * Sessions: at most one active, enforced by a nullable unique column.
 */

test('the database refuses two active academic sessions', function () {
    $session = AcademicSession::factory()->active()->create();

    expect(fn () => DB::table('academic_sessions')->insert([
        'name' => '2027/2028',
        'start_date' => '2027-09-01',
        'end_date' => '2028-08-31',
        'status' => AcademicSessionStatus::ACTIVE->value,
        'active_marker' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(AcademicSession::count())->toBe(1);
    expect($session->fresh()->status)->toBe(AcademicSessionStatus::ACTIVE);
});

test('the database allows any number of non active sessions', function () {
    // A unique index on a plain status column would allow only one of each value, which is
    // why the marker is a separate nullable column: many nulls, one 1.
    AcademicSession::factory()->create(['status' => AcademicSessionStatus::UPCOMING]);
    AcademicSession::factory()->create(['status' => AcademicSessionStatus::UPCOMING]);
    AcademicSession::factory()->completed()->create();

    expect(AcademicSession::count())->toBe(3);
    expect(AcademicSession::query()->current()->count())->toBe(0);
});

test('a session name is unique outright', function () {
    AcademicSession::factory()->create(['name' => '2026/2027']);

    expect(fn () => DB::table('academic_sessions')->insert([
        'name' => '2026/2027',
        'start_date' => '2027-09-01',
        'end_date' => '2028-08-31',
        'status' => AcademicSessionStatus::UPCOMING->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

/*
 * Terms.
 */

test('the database refuses two active terms', function () {
    $session = AcademicSession::factory()->active()->create();
    Term::factory()->forSession($session, 1)->active()->create();

    expect(fn () => DB::table('terms')->insert([
        'academic_session_id' => $session->getKey(),
        'name' => 'Second Term',
        'term_number' => 2,
        'start_date' => '2027-01-11',
        'end_date' => '2027-04-09',
        'status' => TermStatus::ACTIVE->value,
        'active_marker' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(Term::count())->toBe(1);
});

test('a term number is unique within its session but not across sessions', function () {
    $thisYear = AcademicSession::factory()->create();
    $nextYear = AcademicSession::factory()->create();

    Term::factory()->forSession($thisYear, 1)->create();

    expect(fn () => DB::table('terms')->insert([
        'academic_session_id' => $thisYear->getKey(),
        'name' => 'Duplicate',
        'term_number' => 1,
        'start_date' => '2027-01-11',
        'end_date' => '2027-04-09',
        'status' => TermStatus::UPCOMING->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    // The same number in a different year is a different position and must be allowed.
    DB::table('terms')->insert([
        'academic_session_id' => $nextYear->getKey(),
        'name' => 'First Term',
        'term_number' => 1,
        'start_date' => '2027-09-01',
        'end_date' => '2027-12-17',
        'status' => TermStatus::UPCOMING->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(Term::count())->toBe(2);
});

test('a term cannot be filed against a session that does not exist', function () {
    expect(fn () => DB::table('terms')->insert([
        'academic_session_id' => 999999,
        'name' => 'Orphan',
        'term_number' => 1,
        'start_date' => '2026-09-01',
        'end_date' => '2026-12-18',
        'status' => TermStatus::UPCOMING->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

/*
 * The academic structure.
 */

test('a class level name and code are unique outright', function () {
    ClassLevel::factory()->create(['name' => 'Primary', 'code' => 'PRI']);

    expect(fn () => DB::table('class_levels')->insert([
        'name' => 'Primary',
        'code' => 'P2',
        'sort_order' => 1,
        'status' => CatalogStatus::ACTIVE->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('a class name and code are unique within their level only', function () {
    $one = ClassLevel::factory()->create();
    $two = ClassLevel::factory()->create();

    SchoolClass::factory()->within($one, 'Primary 1', 'P1')->create();

    expect(fn () => DB::table('classes')->insert([
        'class_level_id' => $one->getKey(),
        'name' => 'Primary 1',
        'code' => 'P9',
        'sort_order' => 1,
        'status' => CatalogStatus::ACTIVE->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    DB::table('classes')->insert([
        'class_level_id' => $two->getKey(),
        'name' => 'Primary 1',
        'code' => 'P1',
        'sort_order' => 1,
        'status' => CatalogStatus::ACTIVE->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(SchoolClass::count())->toBe(2);
});

test('a section name and code are unique within its class only', function () {
    $one = SchoolClass::factory()->create();
    $two = SchoolClass::factory()->create();

    Section::factory()->within($one, 'A', 'A')->create();

    expect(fn () => DB::table('sections')->insert([
        'school_class_id' => $one->getKey(),
        'name' => 'A',
        'code' => 'Z',
        'sort_order' => 1,
        'status' => CatalogStatus::ACTIVE->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    DB::table('sections')->insert([
        'school_class_id' => $two->getKey(),
        'name' => 'A',
        'code' => 'A',
        'sort_order' => 1,
        'status' => CatalogStatus::ACTIVE->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(Section::count())->toBe(2);
});

/*
 * Referential integrity. The service checks these first to produce a readable message, but
 * the foreign key is what actually holds when something bypasses the service.
 */

test('a class level cannot be deleted while a class still points at it', function () {
    $level = ClassLevel::factory()->create();
    SchoolClass::factory()->create(['class_level_id' => $level->getKey()]);

    expect(fn () => DB::table('class_levels')->where('id', $level->getKey())->delete())
        ->toThrow(QueryException::class);

    $this->assertDatabaseHas('class_levels', ['id' => $level->getKey()]);
});

test('a class cannot be deleted while a section still points at it', function () {
    $class = SchoolClass::factory()->create();
    Section::factory()->create(['school_class_id' => $class->getKey()]);

    expect(fn () => DB::table('classes')->where('id', $class->getKey())->delete())
        ->toThrow(QueryException::class);

    $this->assertDatabaseHas('classes', ['id' => $class->getKey()]);
});

test('a session cannot be deleted while a term still points at it', function () {
    $session = AcademicSession::factory()->create();
    Term::factory()->forSession($session, 1)->create();

    expect(fn () => DB::table('academic_sessions')->where('id', $session->getKey())->delete())
        ->toThrow(QueryException::class);

    $this->assertDatabaseHas('academic_sessions', ['id' => $session->getKey()]);
});

test('no module 02 table carries a school id', function () {
    // The system is single school by design, so a school_id anywhere in these tables would
    // be the first step towards the tenancy this module explicitly rules out.
    foreach (['schools', 'academic_sessions', 'terms', 'class_levels', 'classes', 'sections'] as $table) {
        expect(Schema::hasColumn($table, 'school_id'))->toBeFalse();
    }
});

<?php

use App\Enums\SchoolStatus;
use App\Models\AcademicSession;
use App\Models\School;
use App\Models\Term;
use Database\Seeders\AcademicCalendarSeeder;
use Database\Seeders\SchoolSeeder;

/*
|--------------------------------------------------------------------------
| Seeding
|--------------------------------------------------------------------------
|
| The seeders are part of how an installation is created and re-created, so they are
| asserted here rather than only being run by hand once. A seeder that works the first time
| and fails the second is a real failure: db:seed is re-run during setup, after a config
| change, and by every developer pulling the branch.
|
*/

test('the school seeder creates the one profile', function () {
    $this->seed(SchoolSeeder::class);

    expect(School::count())->toBe(1);
    expect(School::current()?->status)->toBe(SchoolStatus::ACTIVE);
});

test('the school seeder is idempotent', function () {
    $this->seed(SchoolSeeder::class);
    $this->seed(SchoolSeeder::class);
    $this->seed(SchoolSeeder::class);

    // The singleton_key index would reject a second row, so a seeder that appended instead
    // of updating would fail here rather than quietly producing a second school.
    expect(School::count())->toBe(1);
});

test('re-seeding renames the school when the configured name changes', function () {
    config(['school.name' => 'Greenfield International School']);
    $this->seed(SchoolSeeder::class);

    $originalId = School::current()?->getKey();

    config(['school.name' => 'Greenfield Academy']);

    // Looking the existing row up by name - the obvious implementation - would treat the new
    // name as a different school and try to insert a second row, so changing one line of
    // .env and re-seeding would fail with an integrity error. A rename is what changing
    // that variable means, and it has to work.
    $this->seed(SchoolSeeder::class);

    expect(School::count())->toBe(1);
    expect(School::current()?->getKey())->toBe($originalId);
    expect(School::current()?->name)->toBe('Greenfield Academy');
});

test('the school seeder stores an unset website as null rather than an empty string', function () {
    config(['school.website' => null]);

    $this->seed(SchoolSeeder::class);

    // A blank website must not read back as a website the school has. This also guards the
    // model's mutator, which normalises what it is given and so has to tolerate null.
    expect(School::current()?->website)->toBeNull();
});

test('the school seeder normalises the values it stores', function () {
    config([
        'school.name' => '  Greenfield International School  ',
        'school.short_name' => ' gis ',
        'school.website' => ' HTTPS://Greenfield.Example ',
    ]);

    $this->seed(SchoolSeeder::class);

    $school = School::current();

    expect($school?->name)->toBe('Greenfield International School');
    expect($school?->short_name)->toBe('GIS');
    expect($school?->website)->toBe('https://greenfield.example');
});

test('the calendar seeder creates a current session and its terms', function () {
    $this->seed(SchoolSeeder::class);
    $this->seed(AcademicCalendarSeeder::class);

    $session = AcademicSession::findCurrent();

    expect($session)->not->toBeNull();
    expect(Term::query()->current()->count())->toBe(1);
    expect(Term::where('academic_session_id', $session->getKey())->count())->toBe(3);
});

test('the calendar seeder does not replace a calendar that already exists', function () {
    $this->seed(SchoolSeeder::class);
    $this->seed(AcademicCalendarSeeder::class);

    $session = AcademicSession::findCurrent();
    $firstTerm = Term::query()->current()->firstOrFail();

    // Re-seeding is a setup action, not a reset. Running it against a live installation must
    // not roll the school back to a fresh year and lose the term a class is currently in.
    $this->seed(AcademicCalendarSeeder::class);

    expect(AcademicSession::count())->toBe(1);
    expect(AcademicSession::findCurrent()?->getKey())->toBe($session->getKey());
    expect(Term::query()->current()->firstOrFail()->getKey())->toBe($firstTerm->getKey());
});

<?php

use App\Enums\Gender;
use App\Enums\Role;
use App\Enums\StudentStatus;
use App\Enums\UserStatus;
use App\Models\Student;
use App\Models\User;
use App\Services\Student\StudentService;
use Database\Seeders\StudentPermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use RuntimeException;

/*
|--------------------------------------------------------------------------
| Student management: create, read, amend (Module 04)
|--------------------------------------------------------------------------
|
| The three write paths and the read paths, plus the structural claims this module makes
| about itself.
|
*/

it('adds a pupil to the roll without creating a login account', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);

    $response = withToken($token)->postJson('/api/v1/students', studentCreatePayload([
        'middle_name' => 'Ngozi',
        'date_of_birth' => '2015-04-02',
        'gender' => 'FEMALE',
    ]));

    $response->assertCreated()
        ->assertJsonPath('data.first_name', 'Amina')
        ->assertJsonPath('data.middle_name', 'Ngozi')
        ->assertJsonPath('data.last_name', 'Yusuf')
        ->assertJsonPath('data.full_name', 'Amina Ngozi Yusuf')
        ->assertJsonPath('data.gender', 'FEMALE')
        ->assertJsonPath('data.date_of_birth', '2015-04-02');

    // A new pupil is on the roll to be taught. A record born inactive would need a second
    // call before it meant anything.
    expect($response->json('data.status'))->toBe(StudentStatus::ACTIVE->value);

    // The structural claim of the whole module: a pupil exists, and no login was made.
    expect($response->json('data.account_status'))->toBeNull()
        ->and(Student::query()->count())->toBe(1)
        ->and(User::query()->count())->toBe(1) // only the admin who made the call
        ->and($response->json('data.email'))->toBeNull()
        ->and($response->json('data.user_id'))->toBeNull();
});

it('derives a unique student number when the client omits one', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);

    $first = withToken($token)->postJson('/api/v1/students', studentCreatePayload(['last_name' => 'One']));
    $second = withToken($token)->postJson('/api/v1/students', studentCreatePayload(['last_name' => 'Two']));

    $first->assertCreated();
    $second->assertCreated();

    // Derived from the row's own primary key, so the number and the id cannot disagree.
    expect($first->json('data.student_number'))->toBe('STU-'.str_pad((string) $first->json('data.id'), 4, '0', STR_PAD_LEFT))
        ->and($second->json('data.student_number'))->toBe('STU-'.str_pad((string) $second->json('data.id'), 4, '0', STR_PAD_LEFT))
        ->and($first->json('data.student_number'))->not->toBe($second->json('data.student_number'));

    // The placeholder that stands in for the number between the insert and the second
    // statement must never survive the request.
    expect(Student::query()->where('student_number', 'STU-PENDING')->count())->toBe(0);
});

it('accepts a client supplied student number and normalises its spelling', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);

    $response = withToken($token)->postJson('/api/v1/students', studentCreatePayload([
        'student_number' => '  stu-0042 ',
    ]));

    $response->assertCreated()->assertJsonPath('data.student_number', 'STU-0042');

    // A second spelling of the same number is the same number, so it must be refused -
    // otherwise the unique index would be the only thing stopping it, and the error would
    // surface as a 500 rather than a 422.
    withToken($token)->postJson('/api/v1/students', studentCreatePayload([
        'student_number' => 'STU-0042',
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors('student_number');
});

it('creates a pupil whose last name and gender are absent, because a school cannot always know them', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);

    $response = withToken($token)->postJson('/api/v1/students', [
        'first_name' => 'Nameless',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.last_name', null)
        ->assertJsonPath('data.gender', null)
        ->assertJsonPath('data.full_name', 'Nameless');
});

it('requires a first name, which is the name the school calls the child by', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);

    withToken($token)->postJson('/api/v1/students', ['last_name' => 'Yusuf'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('first_name');

    expect(Student::query()->count())->toBe(0);
});

it('refuses a date of birth in the future', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);

    withToken($token)->postJson('/api/v1/students', studentCreatePayload([
        'date_of_birth' => now()->addDay()->toDateString(),
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors('date_of_birth');
});

it('refuses a gender the enum does not define rather than storing it', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);

    // Worth asserting the error names the alternatives: a client told only "invalid" has to
    // guess what the school actually accepts.
    withToken($token)->postJson('/api/v1/students', studentCreatePayload(['gender' => 'OTHER']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('gender')
        ->assertJsonPath('errors.gender.0', 'The gender must be one of: MALE, FEMALE.');

    expect(Student::query()->count())->toBe(0);
});

it('ignores a status on create, because a pupil is put on the roll to be taught', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);

    // "Add a child to the roll, already withdrawn" is a contradiction rather than a state,
    // and the field is simply not one the create accepts. It is ignored rather than
    // rejected, which is the convention Module 03's create set for staff.status: a client
    // that sends a field the endpoint does not define should not be told the whole request
    // failed, since the fields that were defined were applied correctly.
    withToken($token)->postJson('/api/v1/students', studentCreatePayload(['status' => 'WITHDRAWN']))
        ->assertCreated()
        ->assertJsonPath('data.status', StudentStatus::ACTIVE->value);

    expect(Student::query()->sole()->status)->toBe(StudentStatus::ACTIVE);
});

it('shows a single pupil', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);
    $student = pupil(['first_name' => 'Ada', 'last_name' => 'Okonkwo']);

    withToken($token)->getJson("/api/v1/students/{$student->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $student->id)
        ->assertJsonPath('data.first_name', 'Ada')
        ->assertJsonPath('data.status', StudentStatus::ACTIVE->value);
});

it('answers 404 for a pupil who does not exist', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);

    withToken($token)->getJson('/api/v1/students/999999')->assertNotFound();
});

it('lists pupils alphabetically, not newest first', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);

    pupil(['first_name' => 'Zainab', 'last_name' => 'Abubakar']);
    pupil(['first_name' => 'Ada', 'last_name' => 'Okonkwo']);
    pupil(['first_name' => 'Bello', 'last_name' => 'Sani']);

    $response = withToken($token)->getJson('/api/v1/students');

    $response->assertOk();

    // A roll is read the way a school reads it.
    expect(array_column($response->json('data'), 'last_name'))->toBe(['Abubakar', 'Okonkwo', 'Sani']);
});

it('sorts a pupil with no surname last rather than at the top of the alphabet', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);

    pupil(['first_name' => 'Zainab', 'last_name' => 'Abubakar']);
    pupil(['first_name' => 'Nameless', 'last_name' => null]);

    $response = withToken($token)->getJson('/api/v1/students');

    // An empty string sorts before every letter. A missing surname must not read as though
    // it were the first name on the roll.
    expect(array_column($response->json('data'), 'first_name'))->toBe(['Zainab', 'Nameless']);
});

it('amends a pupil record', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);
    $student = pupil(['first_name' => 'Amina', 'last_name' => 'Yusuf']);

    withToken($token)->putJson("/api/v1/students/{$student->id}", studentUpdatePayload([
        'first_name' => 'Amina',
        'last_name' => 'Okonkwo',
        'gender' => 'FEMALE',
    ]))
        ->assertOk()
        ->assertJsonPath('data.last_name', 'Okonkwo')
        ->assertJsonPath('data.gender', 'FEMALE');

    expect($student->refresh()->last_name)->toBe('Okonkwo');
});

it('treats the amend as a whole record write, so omitting the first name is refused', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);
    $student = pupil(['first_name' => 'Amina', 'last_name' => 'Yusuf']);

    withToken($token)->putJson("/api/v1/students/{$student->id}", ['last_name' => 'Changed'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('first_name');

    expect($student->refresh()->last_name)->toBe('Yusuf');
});

it('answers 405 and names the supported methods for a PATCH', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);
    $student = pupil();

    withToken($token)->patchJson("/api/v1/students/{$student->id}", studentUpdatePayload())
        ->assertStatus(405)
        ->assertHeader('Allow');

    expect($student->refresh()->first_name)->toBe($student->first_name);
});

it('refuses a delete outright, because a pupil is not erased from the school', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);
    $student = pupil();

    withToken($token)->deleteJson("/api/v1/students/{$student->id}")->assertStatus(405);

    expect(Student::query()->whereKey($student->id)->exists())->toBeTrue();
});

it('has no delete permission to grant in the first place', function (): void {
    expect(StudentPermissionSeeder::names())
        ->toBe(['students.view', 'students.create', 'students.update'])
        ->not->toContain('students.delete');
});

it('exposes the linked account status when a pupil has one, and only that', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);
    $student = pupilWithAccount(UserStatus::SUSPENDED);

    $response = withToken($token)->getJson("/api/v1/students/{$student->id}");

    $response->assertOk()
        ->assertJsonPath('data.account_status', UserStatus::SUSPENDED->value);

    // The pupil is still on the roll. A suspended login is not a withdrawal, and conflating
    // the two is exactly the mistake Module 03 had to undo.
    expect($response->json('data.status'))->toBe(StudentStatus::ACTIVE->value);

    // Module 01's account data is not the roll's business. A minor's email in particular.
    expect($response->json('data'))->not->toHaveKeys(['email', 'role', 'role_id', 'permissions', 'last_login_at', 'email_verified_at']);
});

it('reports a null account status for a pupil with no login, rather than omitting the field', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);
    $student = pupil();

    $response = withToken($token)->getJson("/api/v1/students/{$student->id}");

    // One field answers both questions, and its absence is not a schema change waiting to
    // break a client.
    $response->assertOk()->assertJsonStructure(['data' => ['account_status']]);

    expect($response->json('data.account_status'))->toBeNull();
});

it('keeps a pupil when their login account is deleted', function (): void {
    $student = pupilWithAccount();

    $student->user->delete();

    $student->refresh();

    // A pupil outlives their portal login. This is the opposite of staff.user_id's CASCADE,
    // and the reason is a person rather than an employee record.
    expect(Student::query()->whereKey($student->id)->exists())->toBeTrue()
        ->and($student->user_id)->toBeNull();
});

it('a pupil can have at most one portal account, and an account at most one pupil', function (): void {
    $first = userWithRole(Role::STUDENT);
    $second = userWithRole(Role::STUDENT);

    // One pupil linked to one account is fine.
    $linked = Student::factory()->forUser($first)->create();

    expect($linked->user_id)->toBe($first->id);

    // A second pupil cannot claim the same account: the unique index means the link is
    // one-to-one, so a login belongs to at most one child.
    expect(fn () => Student::factory()->forUser($first)->create())
        ->toThrow(QueryException::class);

    // A different account links to a different pupil with no trouble.
    $alsoLinked = Student::factory()->forUser($second)->create();

    expect($alsoLinked->user_id)->toBe($second->id);

    // And unlinked pupils are unlimited, because a unique index permits repeated NULLs. That
    // is precisely why the column is nullable: most pupils in Module 04 have no login, and
    // the index constrains the linked case without getting in the way of the rest.
    expect(Student::factory()->create()->user_id)->toBeNull()
        ->and(Student::factory()->create()->user_id)->toBeNull()
        ->and(Student::query()->count())->toBe(4);
});

it('exposes the pupil relationship from the account side', function (): void {
    $student = pupilWithAccount();

    expect($student->user->student->id)->toBe($student->id);
});

it('has no place on a pupil record for a class, section or session', function (): void {
    // The load-bearing structural assertion of the module. Each of these would make the
    // roll answer a question about a single academic session by storing a copy of it.
    expect(Schema::hasColumn('students', 'current_class_id'))->toBeFalse()
        ->and(Schema::hasColumn('students', 'current_section_id'))->toBeFalse()
        ->and(Schema::hasColumn('students', 'current_session_id'))->toBeFalse()
        ->and(Schema::hasColumn('students', 'admission_number'))->toBeFalse()
        ->and(Schema::hasColumn('students', 'class_id'))->toBeFalse()
        ->and(Schema::hasColumn('students', 'session_id'))->toBeFalse();
});

it('derives a number from the pupil id and not from a count, so concurrent creates cannot collide', function (): void {
    $service = app(StudentService::class);

    // The padding is cosmetic; the guarantee is that the id is unique, so no two pupils can
    // derive the same number regardless of how many rows already exist.
    expect($service->deriveStudentNumber(7))->toBe('STU-0007')
        ->and($service->deriveStudentNumber(1234))->toBe('STU-1234')
        ->and($service->deriveStudentNumber(7))->not->toBe($service->deriveStudentNumber(8));
});

it('reserves a different number for every insert, so two pupils added at the same moment cannot collide', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    // The derived number depends on the id the insert produces, so the row has to be written
    // with SOMETHING in student_number first and rewritten immediately afterwards. This test
    // captures whatever that first value was, on every insert, and asserts no two inserts ever
    // write the same one.
    //
    // This is the property the previous test could not check. Deriving from the primary key
    // proves the FINAL numbers differ, but says nothing about the value the row carried in
    // between - and a single fixed reservation value, which is what this code used to write,
    // gives every pupil the same temporary number, so the second of two concurrent creates
    // dies on the unique index and surfaces to the client as a 500.
    //
    // Real concurrency is not simulated and does not need to be. The collision happens if and
    // only if two inserts write the same reservation, which is observable from a single
    // process making two inserts in a row.
    $reserved = [];

    Student::creating(function (Student $student) use (&$reserved): void {
        $reserved[] = $student->student_number;
    });

    withToken($token)->postJson('/api/v1/students', studentCreatePayload(['first_name' => 'Ada']))->assertCreated();
    withToken($token)->postJson('/api/v1/students', studentCreatePayload(['first_name' => 'Emeka']))->assertCreated();

    expect($reserved)->toHaveCount(2)
        ->and($reserved[0])->not->toBe($reserved[1]);
});

it('never leaves a pupil holding a reservation number, even if the derived write fails', function (): void {
    // The reservation is an implementation detail of one insert, not a state a pupil can be
    // observed in. Without a transaction around the two statements, an error between them
    // would commit a pupil whose public number is the internal reservation, and the roll
    // would show it.
    //
    // Rather than reasoning about that failure, this forces it: the derived write throws, and
    // the pupil must not survive. If the two statements were not in a transaction, the insert
    // would have committed before the derived write was even attempted.
    $token = loginAs(userWithRole(Role::ADMIN));

    $forcedFailure = new class extends RuntimeException {};

    // Fail on the SECOND write to this table: the reservation insert succeeds, the derived
    // number write cannot complete. withoutExceptionHandling() so the injected failure
    // propagates instead of being rendered as a 500, which would hide whether the row rolled
    // back or merely failed the request.
    $writes = 0;

    Student::saving(function () use (&$writes, $forcedFailure): void {
        if (++$writes === 2) {
            throw $forcedFailure;
        }
    });

    $this->withoutExceptionHandling();

    expect(fn (): TestResponse => withToken($token)->postJson('/api/v1/students', studentCreatePayload(['first_name' => 'Ada'])))
        ->toThrow($forcedFailure);

    expect(Student::query()->count())->toBe(0)
        ->and(Student::query()->where('student_number', 'like', 'STU-TMP%')->exists())->toBeFalse();
});

it('never exposes a reservation number to a client, on the derived path or the supplied one', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    $derived = withToken($token)->postJson('/api/v1/students', studentCreatePayload(['first_name' => 'Ada']));
    $supplied = withToken($token)->postJson('/api/v1/students', studentCreatePayload(['first_name' => 'Emeka', 'student_number' => 'stu-0777']));

    $derived->assertCreated()->assertJsonPath('data.student_number', 'STU-'.str_pad((string) $derived->json('data.id'), 4, '0', STR_PAD_LEFT));
    $supplied->assertCreated()->assertJsonPath('data.student_number', 'STU-0777');

    // The reservation is unobservable, not merely unlikely to be shown.
    expect(Student::query()->where('student_number', 'like', 'STU-TMP%')->exists())->toBeFalse();
});

it('reads back a name trimmed on the way in, so a roll never shows padded names', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);

    $response = withToken($token)->postJson('/api/v1/students', studentCreatePayload([
        'first_name' => '  Ada  ',
        'last_name' => 'Okonkwo',
    ]));

    $response->assertCreated()->assertJsonPath('data.first_name', 'Ada');

    expect(Student::query()->find($response->json('data.id'))->first_name)->toBe('Ada');
});

it('stores a blank optional name as absent, so a list does not render a doubled space', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);

    $response = withToken($token)->postJson('/api/v1/students', studentCreatePayload([
        'middle_name' => '   ',
    ]));

    $response->assertCreated()->assertJsonPath('data.middle_name', null);

    expect(Student::query()->find($response->json('data.id'))->middle_name)->toBeNull();
});

it('casts the stored values into the enums rather than leaving them as strings', function (): void {
    $student = pupil(['gender' => Gender::FEMALE, 'status' => StudentStatus::WITHDRAWN]);

    expect($student->gender)->toBe(Gender::FEMALE)
        ->and($student->status)->toBe(StudentStatus::WITHDRAWN)
        ->and($student->isTerminal())->toBeTrue()
        ->and($student->isActive())->toBeFalse();
});

it('carries no academic placement column, because placement is an enrollment fact', function (): void {
    // The load-bearing structural assertion of the module. Each of these would make the
    // roll answer a question about a single academic session by storing a copy of it.
    expect(Schema::hasColumn('students', 'current_class_id'))->toBeFalse()
        ->and(Schema::hasColumn('students', 'current_section_id'))->toBeFalse()
        ->and(Schema::hasColumn('students', 'current_session_id'))->toBeFalse()
        ->and(Schema::hasColumn('students', 'admission_number'))->toBeFalse()
        ->and(Schema::hasColumn('students', 'class_id'))->toBeFalse()
        ->and(Schema::hasColumn('students', 'session_id'))->toBeFalse();
});

it('carries no credential column, because identity does not require a login', function (): void {
    expect(Schema::hasColumn('students', 'email'))->toBeFalse()
        ->and(Schema::hasColumn('students', 'password'))->toBeFalse()
        ->and(Schema::hasColumn('students', 'role_id'))->toBeFalse()
        ->and(Schema::hasColumn('students', 'name'))->toBeFalse();
});

it('allows a pupil with no login account, unlike a staff record', function (): void {
    // The asymmetry with Module 03 is deliberate and worth pinning with a test, because it
    // is the kind of decision a later "consistency" pass would quietly reverse.
    $column = fn (string $table): array => collect(Schema::getColumns($table))
        ->firstWhere('name', 'user_id');

    // user_id is nullable on pupils, because most pupils have no login, and not nullable on
    // staff, because a staff record cannot exist without one. MySQL permits repeated NULLs
    // under a unique index, which is exactly the behaviour wanted here: the index constrains
    // the linked case and leaves the unlinked one free.
    expect($column('students')['nullable'])->toBeTrue()
        ->and($column('staff')['nullable'])->toBeFalse();
});

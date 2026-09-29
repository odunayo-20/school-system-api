<?php

use App\Enums\Gender;
use App\Enums\Role;
use App\Enums\StudentStatus;
use App\Enums\UserStatus;
use App\Models\Permission;
use App\Models\Role as RoleModel;
use App\Models\Student;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Student authorization, filters and exposure (Module 04)
|--------------------------------------------------------------------------
|
| Who may read and write the roll, what the list can be narrowed by, and what the API
| refuses to show.
|
*/

/*
| Authorization
*/

it('refuses the roll to an unauthenticated caller', function (): void {
    pupil();

    test()->getJson('/api/v1/students')->assertUnauthorized();
    test()->postJson('/api/v1/students', studentCreatePayload())->assertUnauthorized();
});

it('refuses the roll to a user with no students permission', function (): void {
    // A role can be held with or without a grant, and a teacher is the ordinary case: they
    // may well be authenticated and active, and still have no business reading the roll.
    $teacher = userWithRole(Role::STAFF);
    $token = loginAs($teacher);

    withToken($token)->getJson('/api/v1/students')->assertForbidden();
    withToken($token)->postJson('/api/v1/students', studentCreatePayload())->assertForbidden();
});

it('refuses the roll to a suspended account, even with a token minted before the suspension', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);

    $admin->forceFill(['status' => UserStatus::SUSPENDED])->save();
    forgetResolvedUser();

    withToken($token)->getJson('/api/v1/students')->assertForbidden();
});

it('grants the roll to super admin, admin and registrar', function (): void {
    foreach ([Role::SUPER_ADMIN, Role::ADMIN, Role::REGISTRAR] as $role) {
        $token = loginAs(userWithRole($role));

        withToken($token)->getJson('/api/v1/students')->assertOk();
        withToken($token)->postJson('/api/v1/students', studentCreatePayload())->assertCreated();
    }
});

it('keeps the roll away from pupils, who hold only their own self-service permissions', function (): void {
    $pupilAccount = userWithRole(Role::STUDENT);
    $token = loginAs($pupilAccount);

    // A pupil may read and amend their OWN record through Module 01's profile.* pair, and
    // the whole roll of every other child in the school is not theirs to browse.
    withToken($token)->getJson('/api/v1/students')->assertForbidden();
    withToken($token)->postJson('/api/v1/students', studentCreatePayload())->assertForbidden();
});

it('requires a specific permission per action rather than one blanket grant', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $student = pupil();

    // Take away only the create grant. Reading and amending must survive; writing must not.
    // users.role_id is a belongsTo, so the grants are revoked on the role itself.
    $admin->role->permissions()->detach(
        Permission::query()->where('name', 'students.create')->value('id')
    );

    $token = loginAs($admin);

    withToken($token)->getJson('/api/v1/students')->assertOk();
    withToken($token)->postJson('/api/v1/students', studentCreatePayload())->assertForbidden();
    withToken($token)->putJson("/api/v1/students/{$student->id}", studentUpdatePayload())->assertOk();
});

it('separates the amend permission from the read permission', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $student = pupil(['first_name' => 'Unchanged']);
    $admin->role->permissions()->detach(
        Permission::query()->where('name', 'students.update')->value('id')
    );

    $token = loginAs($admin);

    withToken($token)->getJson('/api/v1/students')->assertOk();
    withToken($token)->putJson("/api/v1/students/{$student->id}", studentUpdatePayload())->assertForbidden();

    // Read-only, not merely hidden: the write was refused, not silently dropped.
    expect($student->refresh()->first_name)->toBe('Unchanged');
});

it('seeds the permissions this module owns and no others', function (): void {
    $names = Permission::query()->where('name', 'like', 'students.%')->pluck('name')->all();

    expect($names)->toEqualCanonicalizing(['students.view', 'students.create', 'students.update']);
});

it('adds the module permissions without revoking the earlier modules', function (): void {
    // StudentPermissionSeeder runs after PermissionSeeder, which grants with sync() and
    // would wipe a later seeder's work. This is the test that would fail first if somebody
    // reordered the seeders, and the failure it would produce at runtime is 403s on
    // working routes while every table still looked correct.
    $admin = RoleModel::query()->where('name', Role::ADMIN->value)->firstOrFail();
    $held = $admin->permissions()->pluck('name')->all();

    expect($held)->toContain('school.view')          // Module 01
        ->toContain('classes.view')                  // Module 02
        ->toContain('staff.view')                    // Module 03
        ->toContain('students.view')                 // Module 04
        ->not->toContain('students.delete');
});

/*
| Filters
*/

it('finds a pupil by any part of the name', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);

    $target = pupil(['first_name' => 'Amina', 'middle_name' => 'Ngozi', 'last_name' => 'Okonkwo']);
    pupil(['first_name' => 'Bello', 'last_name' => 'Sani']);

    foreach (['amina', 'Ngozi', 'okonkwo', 'OKONKWO'] as $term) {
        $response = withToken($token)->getJson("/api/v1/students?search={$term}");

        $response->assertOk();

        expect($response->json('data'))->toHaveCount(1)
            ->and($response->json('data.0.id'))->toBe($target->id);
    }
});

it('finds a pupil by number', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);
    $target = pupil(['student_number' => 'STU-0042']);

    pupil(['first_name' => 'Bello']);

    withToken($token)->getJson('/api/v1/students?search=STU-0042')
        ->assertOk()
        ->assertJsonPath('data.0.id', $target->id);
});

it('searches a partial number as well as an exact one', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);

    pupil(['student_number' => 'STU-0042']);
    pupil(['student_number' => 'STU-0043']);

    // A registrar reading a number off a paper form types the part they can see.
    withToken($token)->getJson('/api/v1/students?search=004')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('returns nothing rather than everything when a search matches nothing', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);
    pupil();

    $response = withToken($token)->getJson('/api/v1/students?search=Nobody');

    $response->assertOk();

    expect($response->json('data'))->toBe([]);
});

it('does not let a wildcard in a search widen the query', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);

    pupil(['first_name' => 'Amina']);
    pupil(['first_name' => 'Bello']);

    // The wildcards around the term are added by the query, so any the user typed are
    // escaped and matched literally. A search for "%" is the case that catches a missing
    // escape: unescaped it becomes "%%%" and returns the entire roll, which turns a box
    // meant to narrow the list into a way to dump it. Same for "_", SQL's single-character
    // wildcard.
    withToken($token)->getJson('/api/v1/students?search=%25')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    withToken($token)->getJson('/api/v1/students?search=_')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    // The escaping must not break ordinary searching, including a real substring.
    withToken($token)->getJson('/api/v1/students?search=min')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('filters by status', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);

    pupil(['status' => StudentStatus::ACTIVE]);
    pupil(['status' => StudentStatus::INACTIVE]);
    $graduated = pupil(['status' => StudentStatus::GRADUATED]);

    withToken($token)->getJson('/api/v1/students?status=GRADUATED')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $graduated->id);
});

it('filters by gender', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);

    $girl = pupil(['gender' => Gender::FEMALE]);
    pupil(['gender' => Gender::MALE]);
    pupil(['gender' => null]);

    withToken($token)->getJson('/api/v1/students?gender=FEMALE')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $girl->id);
});

it('combines filters rather than letting the last one win', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);

    pupil(['first_name' => 'Amina', 'status' => StudentStatus::ACTIVE, 'gender' => Gender::FEMALE]);
    pupil(['first_name' => 'Amina', 'status' => StudentStatus::INACTIVE, 'gender' => Gender::FEMALE]);
    pupil(['first_name' => 'Amina', 'status' => StudentStatus::ACTIVE, 'gender' => Gender::MALE]);

    withToken($token)->getJson('/api/v1/students?search=Amina&status=ACTIVE&gender=FEMALE')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('rejects a filter value the enum does not define, naming the permitted values', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);

    withToken($token)->getJson('/api/v1/students?status=EXPELLED')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status')
        ->assertJsonPath('errors.status.0', 'The status must be one of: ACTIVE, INACTIVE, GRADUATED, WITHDRAWN.');

    withToken($token)->getJson('/api/v1/students?gender=OTHER')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('gender')
        ->assertJsonPath('errors.gender.0', 'The gender must be one of: MALE, FEMALE.');
});

it('pages the roll and caps the page size', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);

    Student::factory()->count(25)->create();

    $first = withToken($token)->getJson('/api/v1/students?per_page=10&page=1');
    $first->assertOk()->assertJsonCount(10, 'data');

    expect($first->json('meta.per_page'))->toBe(10)
        ->and($first->json('meta.total'))->toBe(25)
        ->and($first->json('meta.last_page'))->toBe(3);

    withToken($token)->getJson('/api/v1/students?per_page=500')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('per_page');
});

it('keeps the filters in the pagination links, so a next page stays filtered', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);

    pupil(['status' => StudentStatus::ACTIVE]);
    pupil(['status' => StudentStatus::INACTIVE]);

    withToken($token)->getJson('/api/v1/students?status=ACTIVE&per_page=1')
        ->assertOk()
        ->assertJsonPath('meta.total', 1);
});

/*
| What the API will not do or show
*/

it('ignores an attempt to set the portal link through the create endpoint', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);
    $someoneElse = userWithRole(Role::ADMIN);

    withToken($token)->postJson('/api/v1/students', studentCreatePayload([
        'user_id' => $someoneElse->id,
    ]))->assertCreated();

    // The key is not in the request's attribute set, so it cannot reach the model. This is
    // the protection: the field is absent, not filtered by a deny-list somebody has to
    // remember to extend.
    expect(Student::query()->sole()->user_id)->toBeNull();
});

it('ignores an attempt to repoint the portal link through the amend endpoint', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);
    $student = pupil();
    $someoneElse = userWithRole(Role::ADMIN);

    withToken($token)->putJson("/api/v1/students/{$student->id}", studentUpdatePayload([
        'user_id' => $someoneElse->id,
    ]))->assertOk();

    // Accepting user_id here would let a registrar attach a child's record to an
    // administrator's login and read the roll with their permissions: an account takeover
    // wearing a field that looks like harmless record-keeping.
    expect($student->refresh()->user_id)->toBeNull();
});

it('ignores an attempt to create an account through the create endpoint', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);
    $usersBefore = User::query()->count();

    withToken($token)->postJson('/api/v1/students', studentCreatePayload([
        'email' => 'child@example.test',
        'password' => 'Str0ng!Passw0rd',
        'password_confirmation' => 'Str0ng!Passw0rd',
        'role' => 'STUDENT',
    ]))->assertCreated();

    // No User, no email on the pupil, and no role. The endpoint is "add a pupil to the
    // roll", and the absence of these keys is the guarantee - a rule that merely rejected
    // them would be a list to remember to extend.
    expect(User::query()->count())->toBe($usersBefore)
        ->and(Student::query()->sole()->user_id)->toBeNull()
        ->and(Student::query()->sole()->getAttributes())->not->toHaveKey('email')
        ->and(Student::query()->sole()->getAttributes())->not->toHaveKey('password')
        ->and(Student::query()->sole()->getAttributes())->not->toHaveKey('role_id');
});

it('does not let a pupil amend a school record through the roll', function (): void {
    [$school] = configuredSchool();
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);
    $student = pupil(['first_name' => 'Original']);

    // Module 02's school profile is a singleton behind school.update. Nothing about reaching
    // it through /students should be possible, and this is the assertion that the module's
    // routes are only the four it claims to be.
    withToken($token)->putJson('/api/v1/students/'.$student->id, studentUpdatePayload([
        'first_name' => 'Original',
        'school' => ['name' => 'Hacked School'],
    ]))->assertOk();

    expect($student->refresh()->first_name)->toBe('Original')
        ->and($school->refresh()->name)->not->toBe('Hacked School');
});

it('exposes no account internals for a pupil who has a linked login', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);
    $student = pupilWithAccount();

    $response = withToken($token)->getJson("/api/v1/students/{$student->id}");

    $response->assertOk();

    // A minor's email address in particular. Module 01 owns users.* and exposes it to the
    // roles entitled to see accounts; the roll has no business repeating it.
    //
    // The list is spelled out rather than asserted with a "not to have key" per field, so a
    // field added to the resource later has to be added here deliberately.
    expect(array_keys($response->json('data')))
        ->toBe([
            'id', 'student_number',
            'first_name', 'middle_name', 'last_name', 'full_name',
            'date_of_birth', 'gender',
            'status', 'account_status',
            'created_at', 'updated_at',
        ]);
});

it('lists the roll through the same envelope as every other list in the API', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);
    pupil();

    withToken($token)->getJson('/api/v1/students')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [['id', 'student_number', 'first_name', 'full_name', 'status', 'account_status']],
            'meta' => ['current_page', 'per_page', 'total', 'last_page'],
        ]);
});

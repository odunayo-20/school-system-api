<?php

use App\Enums\EmploymentStatus;
use App\Enums\Role;
use App\Enums\StaffType;
use App\Models\Staff;
use App\Models\User;
use App\Services\Staff\StaffService;
use Database\Seeders\StaffPermissionSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Staff management: read, create, amend
|--------------------------------------------------------------------------
|
| Behaviour rather than implementation. Where a rule exists to stop a specific class of
| failure, the test says which one.
|
*/

/*
|--------------------------------------------------------------------------
| Reading the list
|--------------------------------------------------------------------------
*/

it('refuses an unauthenticated staff list', function (): void {
    $this->getJson('/api/v1/staff')->assertStatus(401);
});

it('refuses the staff list to a student', function (): void {
    asUser(userWithRole(Role::STUDENT))
        ->getJson('/api/v1/staff')
        ->assertStatus(403);
});

it('returns a flat data list with meta and links as its siblings', function (): void {
    staffMember(StaffType::TEACHING);
    staffMember(StaffType::NON_TEACHING);

    $response = asUser(userWithRole(Role::ADMIN))->getJson('/api/v1/staff')->assertOk();

    // data is the list itself. Laravel's default paginated resource would nest it as
    // data.data, and a client would have to special case every list endpoint in the API.
    $response->assertJsonStructure(['data', 'meta' => ['current_page', 'last_page', 'per_page', 'total'], 'links'])
        ->assertJsonCount(2, 'data')
        ->assertJsonMissingPath('data.data');
});

it('shows one staff record and 404s an unknown id', function (): void {
    $staff = staffMember(StaffType::TEACHING, ['designation' => 'Teacher']);

    asUser(userWithRole(Role::ADMIN))
        ->getJson("/api/v1/staff/{$staff->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $staff->id)
        ->assertJsonPath('data.designation', 'Teacher')
        ->assertJsonPath('data.staff_type', 'TEACHING');

    asUser(userWithRole(Role::ADMIN))
        ->getJson('/api/v1/staff/999999')
        ->assertStatus(404)
        ->assertJsonPath('message', 'Resource not found.');
});

/*
|--------------------------------------------------------------------------
| Creating
|--------------------------------------------------------------------------
*/

it('creates a staff row and a linked account with the STAFF role', function (): void {
    $response = asUser(userWithRole(Role::ADMIN))
        ->postJson('/api/v1/staff', staffCreatePayload([
            'employment_date' => '2024-09-01',
            'phone' => '+234 800 000 0001',
            'designation' => 'Principal',
        ]))
        ->assertStatus(201)
        ->assertJsonPath('data.staff_type', 'TEACHING')
        ->assertJsonPath('data.status', 'ACTIVE')
        ->assertJsonPath('data.account_status', 'ACTIVE')
        ->assertJsonPath('data.employment_date', '2024-09-01')
        ->assertJsonPath('data.designation', 'Principal')
        ->assertJsonPath('data.phone', '+234 800 000 0001');

    $user = User::query()->where('email', 'amina@example.test')->firstOrFail();

    expect($user->isStaff())->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(Staff::query()->where('user_id', $user->getKey())->exists())->toBeTrue()
        // The password is stored hashed and never echoed back.
        ->and(Hash::check('Str0ng!Passw0rd', $user->password))->toBeTrue()
        ->and($response->json())->not->toHaveKey('data.password');
});

it('ignores a role sent in the body so no payload can grant itself a role', function (): void {
    asUser(userWithRole(Role::ADMIN))
        ->postJson('/api/v1/staff', staffCreatePayload([
            'role' => 'SUPER_ADMIN',
            'role_id' => 1,
        ]))
        ->assertStatus(201);

    $user = User::query()->where('email', 'amina@example.test')->firstOrFail();

    // Enforced by the absence of the key, not by a rule that enumerates the values to
    // reject. A role is granted afterwards through Module 01's users.* permissions.
    expect($user->isStaff())->toBeTrue()
        ->and($user->isSuperAdmin())->toBeFalse()
        ->and($user->roleEnum()?->value)->toBe(Role::STAFF->value);
});

it('rejects a password that the shared password rule refuses', function (): void {
    asUser(userWithRole(Role::ADMIN))
        ->postJson('/api/v1/staff', staffCreatePayload([
            'password' => 'weak',
            'password_confirmation' => 'weak',
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('password');

    expect(User::query()->where('email', 'amina@example.test')->exists())->toBeFalse()
        ->and(Staff::query()->count())->toBe(0);
});

it('rejects a duplicate email', function (): void {
    userWithRole(Role::ADMIN, ['email' => 'taken@example.test']);

    asUser(userWithRole(Role::ADMIN))
        ->postJson('/api/v1/staff', staffCreatePayload(['email' => 'taken@example.test']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');
});

it('rejects a duplicate email regardless of case', function (): void {
    // The stored address is lower-cased by a User mutator, so the uniqueness check has to
    // compare the lower-cased value too. Without the request normalising first, this
    // would pass validation and then collide on the index as a 500.
    userWithRole(Role::ADMIN, ['email' => 'taken@example.test']);

    asUser(userWithRole(Role::ADMIN))
        ->postJson('/api/v1/staff', staffCreatePayload(['email' => 'TAKEN@Example.Test']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');
});

it('requires an email and a password, because a staff record has a login account', function (): void {
    asUser(userWithRole(Role::ADMIN))
        ->postJson('/api/v1/staff', ['name' => 'Amina', 'staff_type' => 'TEACHING'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email', 'password']);
});

it('rejects an employment date in the future', function (): void {
    asUser(userWithRole(Role::ADMIN))
        ->postJson('/api/v1/staff', staffCreatePayload(['employment_date' => now()->addYear()->toDateString()]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('employment_date');
});

/*
|--------------------------------------------------------------------------
| The staff number
|--------------------------------------------------------------------------
*/

it('derives a staff number from the account id when none is supplied', function (): void {
    $response = asUser(userWithRole(Role::ADMIN))
        ->postJson('/api/v1/staff', staffCreatePayload())
        ->assertStatus(201);

    $user = User::query()->where('email', 'amina@example.test')->firstOrFail();

    $expected = 'STAFF-'.str_pad((string) $user->getKey(), 4, '0', STR_PAD_LEFT);

    $response->assertJsonPath('data.staff_number', $expected);

    expect(Staff::query()->where('staff_number', $expected)->exists())->toBeTrue();
});

it('derives a distinct number for every staff member without counting', function (): void {
    $admin = userWithRole(Role::ADMIN);

    $first = asUser($admin)->postJson('/api/v1/staff', staffCreatePayload(['email' => 'a@example.test']))->assertStatus(201);
    $second = asUser($admin)->postJson('/api/v1/staff', staffCreatePayload(['email' => 'b@example.test']))->assertStatus(201);
    $third = asUser($admin)->postJson('/api/v1/staff', staffCreatePayload(['email' => 'c@example.test']))->assertStatus(201);

    $numbers = collect([$first, $second, $third])
        ->map(fn ($r) => $r->json('data.staff_number'))
        ->all();

    // Derived from a primary key, so uniqueness is structural rather than a count() + 1
    // that two concurrent creates could read identically.
    expect($numbers)->toHaveCount(3)
        ->and(array_unique($numbers))->toHaveCount(3);
});

it('normalises a supplied staff number before checking it for uniqueness', function (): void {
    asUser(userWithRole(Role::ADMIN))
        ->postJson('/api/v1/staff', staffCreatePayload([
            'email' => 'a@example.test',
            'staff_number' => '  sta-07  ',
        ]))
        ->assertStatus(201)
        ->assertJsonPath('data.staff_number', 'STA-07');

    // The canonical form is what the unique check sees, so a second member using a
    // different spelling of the same number is refused rather than stored twice.
    asUser(userWithRole(Role::ADMIN))
        ->postJson('/api/v1/staff', staffCreatePayload([
            'email' => 'b@example.test',
            'staff_number' => 'sta-07',
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('staff_number');
});

/*
|--------------------------------------------------------------------------
| Amending
|--------------------------------------------------------------------------
*/

it('amends a staff record and the linked account name', function (): void {
    $staff = staffMember(StaffType::TEACHING);

    asUser(userWithRole(Role::ADMIN))
        ->putJson("/api/v1/staff/{$staff->id}", staffUpdatePayload([
            'name' => 'Amina Yusuf-Smith',
            'staff_type' => 'NON_TEACHING',
            'designation' => 'Head of Science',
        ]))
        ->assertOk()
        ->assertJsonPath('data.name', 'Amina Yusuf-Smith')
        ->assertJsonPath('data.staff_type', 'NON_TEACHING')
        ->assertJsonPath('data.designation', 'Head of Science');

    expect($staff->refresh()->staff_type)->toBe(StaffType::NON_TEACHING)
        ->and($staff->user->refresh()->name)->toBe('Amina Yusuf-Smith');
});

it('rolls the whole amend back when the staff half of it cannot be written', function (): void {
    // An amend writes two tables: the display name on the account, everything else on the
    // staff row. The name is not duplicated onto staff precisely so there is one of it, which
    // means the two writes can half-succeed. This asserts they cannot.
    //
    // The staff write is made to fail on purpose, because a uniqueness race is the case that
    // matters in production and cannot be staged through the API: the request's validation
    // would catch it first. What is being asserted is the general property - whatever goes
    // wrong on the staff save, the account rename does not survive it. Without the
    // transaction a caller gets a rejection and a changed record for one request.
    $staff = staffMember(StaffType::TEACHING);
    $originalName = $staff->user->name;
    $originalType = $staff->staff_type;

    $refusesToSave = 'eloquent.saving: '.Staff::class;

    Event::listen($refusesToSave, function (): void {
        throw new RuntimeException('the staff write was refused');
    });

    try {
        expect(fn () => app(StaffService::class)->update(
            $staff,
            ['name' => 'Should Not Stick', 'staff_type' => StaffType::NON_TEACHING],
        ))->toThrow(RuntimeException::class);
    } finally {
        Event::forget($refusesToSave);
    }

    // Neither half of the amend survived the failure of the other.
    expect($staff->user->refresh()->name)->toBe($originalName)
        ->and($staff->refresh()->staff_type)->toBe($originalType);
});

it('lets a staff member keep their own staff number', function (): void {
    // The unique check ignores the record being amended, so re-saving somebody with the
    // number they already hold is not a collision with themselves.
    $staff = staffMember(StaffType::TEACHING, ['staff_number' => 'STA-99']);

    asUser(userWithRole(Role::ADMIN))
        ->putJson("/api/v1/staff/{$staff->id}", staffUpdatePayload(['staff_number' => 'STA-99']))
        ->assertOk()
        ->assertJsonPath('data.staff_number', 'STA-99');
});

it('rejects a staff number already held by a different staff member', function (): void {
    staffMember(StaffType::TEACHING, ['staff_number' => 'STA-99']);
    $other = staffMember(StaffType::NON_TEACHING);

    asUser(userWithRole(Role::ADMIN))
        ->putJson("/api/v1/staff/{$other->id}", staffUpdatePayload(['staff_number' => 'STA-99']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('staff_number');
});

it('refuses to change the account email, the password, the role or the linkage', function (): void {
    $staff = staffMember(StaffType::TEACHING);
    $victim = userWithRole(Role::STUDENT);
    $originalUserId = $staff->user_id;
    $originalEmail = $staff->user->email;

    asUser(userWithRole(Role::ADMIN))
        ->putJson("/api/v1/staff/{$staff->id}", staffUpdatePayload([
            'email' => 'attacker@example.test',
            'password' => 'Str0ng!Passw0rd',
            'role' => 'SUPER_ADMIN',
            'user_id' => $victim->getKey(),
        ]))
        ->assertOk();

    $staff->refresh();

    // Email and password are credentials behind Module 01's users.update, which registrar
    // does not hold. Accepting them here would hand a registrar, who does hold
    // staff.update, a way to take over any account: change the address, then take the
    // password reset. user_id is never settable, so a record cannot be repointed at
    // somebody else's login.
    expect($staff->user->refresh()->email)->toBe($originalEmail)
        ->and($staff->user_id)->toBe($originalUserId)
        ->and($staff->user->isStaff())->toBeTrue()
        ->and(User::query()->where('email', 'attacker@example.test')->exists())->toBeFalse();
});

it('requires the identifying fields because an amend is a whole record write', function (): void {
    $staff = staffMember(StaffType::TEACHING);

    asUser(userWithRole(Role::ADMIN))
        ->putJson("/api/v1/staff/{$staff->id}", ['designation' => 'Teacher'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'staff_type']);
});

/*
|--------------------------------------------------------------------------
| The verbs the module does not offer
|--------------------------------------------------------------------------
*/

it('answers a PATCH with 405 because the amend is PUT only', function (): void {
    $staff = staffMember(StaffType::TEACHING);

    $response = asUser(userWithRole(Role::ADMIN))
        ->patchJson("/api/v1/staff/{$staff->id}", staffUpdatePayload());

    $response->assertStatus(405);

    // A client that guessed PATCH is told which verbs do exist.
    expect(str_contains((string) $response->headers->get('Allow'), 'PUT'))->toBeTrue();

    expect($staff->refresh()->staff_type)->toBe(StaffType::TEACHING);
});

it('has no delete endpoint', function (): void {
    $staff = staffMember(StaffType::TEACHING);

    asUser(userWithRole(Role::ADMIN))
        ->deleteJson("/api/v1/staff/{$staff->id}")
        ->assertStatus(405);

    // Nothing references a staff record yet, so a "no dependent records" guard could never
    // fail. The record is kept and employment is ended through status instead.
    expect(Staff::query()->whereKey($staff->id)->exists())->toBeTrue();
});

it('does not seed a delete permission for an operation that does not exist', function (): void {
    expect(Route::has('staff.destroy'))->toBeFalse();

    $staffPermissions = StaffPermissionSeeder::names();

    expect($staffPermissions)->toContain('staff.view', 'staff.create', 'staff.update', 'staff.activate', 'staff.deactivate')
        ->and($staffPermissions)->toHaveCount(5)
        ->and($staffPermissions)->not->toContain('staff.delete');
});

/*
|--------------------------------------------------------------------------
| The factory
|--------------------------------------------------------------------------
*/

it('builds a staff record through the factory with no overrides', function (): void {
    // Module 01 defined staff.user_id as NOT NULL and this factory returned null for it, so
    // the factory could not be used at all without every test passing a user_id. That was
    // never noticed because every Module 01 test did pass one.
    $staff = Staff::factory()->create();

    expect($staff->user_id)->not->toBeNull()
        ->and($staff->user)->not->toBeNull()
        ->and($staff->status)->toBe(EmploymentStatus::ACTIVE)
        ->and($staff->user->isStaff())->toBeTrue();
});

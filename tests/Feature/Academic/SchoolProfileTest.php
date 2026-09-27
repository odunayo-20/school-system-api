<?php

use App\Enums\Role;
use App\Enums\SchoolStatus;
use App\Enums\UserStatus;
use App\Models\School;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('reading the school profile requires authentication', function () {
    $this->getJson('/api/v1/school')->assertUnauthorized();
});

test('reading the school profile requires the view permission', function () {
    asUser(userWithRole(Role::STUDENT))->getJson('/api/v1/school')->assertForbidden();
});

test('an unconfigured installation reports a null profile rather than an error', function () {
    // A fresh installation has no school profile, and that is a normal state to be in
    // rather than a client error, so this must not be a 404 the caller has to special case.
    asUser(userWithRole(Role::ADMIN))
        ->getJson('/api/v1/school')
        ->assertOk()
        // assertJsonPath alone cannot tell "data is null" from "data is absent", and the two
        // mean different things to a client, so the structure is asserted too: the key must
        // be present and hold null.
        ->assertJsonStructure(['data', 'message'])
        ->assertJsonPath('data', null)
        ->assertJsonFragment(['message' => 'The school profile has not been configured yet. Send a PUT to /api/v1/school to create it.']);
});

test('the profile is created by the same endpoint that amends it', function () {
    asUser(userWithRole(Role::ADMIN))
        ->putJson('/api/v1/school', [
            'name' => 'Greenfield International School',
            'short_name' => 'gis',
            'email' => 'office@example.test',
            'website' => 'HTTPS://Example.TEST',
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Greenfield International School')
        // Normalised by the model, so a report heading cannot render "gis" and "GIS" as
        // two different schools.
        ->assertJsonPath('data.short_name', 'GIS')
        ->assertJsonPath('data.website', 'https://example.test')
        ->assertJsonPath('data.status', 'ACTIVE');

    expect(School::count())->toBe(1);
});

test('updating the profile amends the existing record instead of creating a second', function () {
    $school = School::factory()->active()->create(['name' => 'Old Name']);

    asUser(userWithRole(Role::ADMIN))
        ->putJson('/api/v1/school', ['name' => 'New Name', 'short_name' => 'NN'])
        ->assertOk()
        ->assertJsonPath('data.name', 'New Name')
        ->assertJsonPath('data.id', $school->id);

    expect(School::count())->toBe(1);
});

test('the school profile is required to be a singleton even against a raw write', function () {
    School::factory()->active()->create();

    // A nullable unique "active marker" would allow this, which is why the table uses a
    // NOT NULL singleton key instead. Asserted through a query builder insert so it proves
    // the constraint is in the database and not only in the model.
    expect(fn () => DB::table('schools')->insert([
        'name' => 'A Second School',
        'short_name' => 'TWO',
        'status' => 'INACTIVE',
        'singleton_key' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(School::count())->toBe(1);
});

test('marking the school inactive does not make its profile disappear', function () {
    // The status is advisory. Filtering the current school by status would hide the
    // profile and strand the academic context for an ordinary "school is closed" action.
    $school = School::factory()->create(['status' => SchoolStatus::INACTIVE]);

    asUser(userWithRole(Role::ADMIN))
        ->getJson('/api/v1/school')
        ->assertOk()
        ->assertJsonPath('data.id', $school->id)
        ->assertJsonPath('data.status', 'INACTIVE');
});

test('the profile payload never exposes the internal singleton key', function () {
    School::factory()->active()->create();

    asUser(userWithRole(Role::ADMIN))
        ->getJson('/api/v1/school')
        ->assertOk()
        ->assertJsonMissingPath('data.singleton_key');
});

test('the profile payload validates its input', function () {
    asUser(userWithRole(Role::ADMIN))
        ->putJson('/api/v1/school', [
            'name' => '',
            'short_name' => 'X',
            'email' => 'not-an-email',
            'website' => 'example.test',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'email', 'website'])
        // The custom text belongs to the field, not to the aggregated top level message.
        ->assertJsonPath('errors.website.0', 'The website must be a valid URL, including the scheme, for example https://example.test.');

    expect(School::count())->toBe(0);
});

test('updating the profile requires the update permission', function () {
    asUser(userWithRole(Role::STUDENT))
        ->putJson('/api/v1/school', ['name' => 'Nope', 'short_name' => 'NO'])
        ->assertForbidden();

    // A staff member may read the profile but must not be able to change it.
    asUser(userWithRole(Role::STAFF))
        ->putJson('/api/v1/school', ['name' => 'Nope', 'short_name' => 'NO'])
        ->assertForbidden();

    expect(School::count())->toBe(0);
});

test('a permission granted directly is enough without changing the role', function () {
    $registrar = userWithRole(Role::REGISTRAR);

    expect($registrar->hasPermission('school.update'))->toBeFalse();

    grantPermission($registrar, 'school.update');
    forgetResolvedUser();

    asUser($registrar)
        ->putJson('/api/v1/school', ['name' => 'Granted', 'short_name' => 'GR'])
        ->assertOk();
});

test('a suspended user cannot amend the profile with an old token', function () {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);

    $admin->forceFill(['status' => UserStatus::SUSPENDED])->save();
    forgetResolvedUser();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson('/api/v1/school', ['name' => 'Nope', 'short_name' => 'NO'])
        ->assertForbidden();

    expect(School::count())->toBe(0);
});

<?php

use App\Enums\EmploymentStatus;
use App\Enums\Role;
use App\Enums\StaffType;
use App\Enums\UserStatus;
use App\Models\Staff;
use Illuminate\Database\UniqueConstraintViolationException;

/*
|--------------------------------------------------------------------------
| What the API exposes, what it filters, and who may do it
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| Exposure
|--------------------------------------------------------------------------
*/

/**
 * Every key at any depth of a decoded payload, so a check does not have to be a list of
 * expected keys. A new column on staff or users shows up here the day it is added to a
 * resource, rather than the day somebody notices.
 *
 * @return list<string>
 */
function payloadKeys(mixed $value): array
{
    if (! is_array($value)) {
        return [];
    }

    $keys = [];

    foreach ($value as $key => $child) {
        $keys[] = (string) $key;
        $keys = array_merge($keys, payloadKeys($child));
    }

    return $keys;
}

it('never exposes a credential or a token anywhere in a staff payload', function (): void {
    $staff = staffMember(StaffType::TEACHING);
    $staff->user->createToken('leak-check')->plainTextToken;

    $admin = userWithRole(Role::ADMIN);

    $list = asUser($admin)->getJson('/api/v1/staff')->assertOk();
    $show = asUser($admin)->getJson("/api/v1/staff/{$staff->id}")->assertOk();
    $create = asUser($admin)->postJson('/api/v1/staff', staffCreatePayload(['email' => 'new@example.test']))->assertStatus(201);

    foreach ([$list->json(), $show->json(), $create->json()] as $payload) {
        $keys = payloadKeys($payload);

        foreach ([
            'password',
            'remember_token',
            'password_reset_tokens',
            'token',
            'tokens',
            'plainTextToken',
            'role',
            'role_id',
            'email_verified_at',
            'last_login_at',
        ] as $forbidden) {
            expect($keys)->not->toContain($forbidden);
        }

        // The raw hash and the actual token string, in case either is echoed in a value.
        // Scoped to the data payload: a human-readable message is allowed to mention the
        // word "password" when it explains that the account was created with the one the
        // caller supplied, and that is prose rather than a leaked value.
        expect(json_encode($payload['data']))
            ->not->toContain('$2y$')
            ->not->toContain('password')
            ->not->toContain('remember_token');
    }
});

it('exposes the exact field set the API contract promises', function (): void {
    $staff = staffMember(StaffType::TEACHING);

    asUser(userWithRole(Role::ADMIN))
        ->getJson("/api/v1/staff/{$staff->id}")
        ->assertOk()
        ->assertJsonStructure(['data' => [
            'id', 'staff_number', 'name', 'email', 'staff_type', 'designation',
            'employment_date', 'phone', 'status', 'account_status', 'created_at', 'updated_at',
        ]]);
});

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

it('searches by staff number, by name and by email', function (): void {
    $byNumber = staffMember(StaffType::TEACHING, ['staff_number' => 'STA-77']);
    $byNumber->user->forceFill(['name' => 'Zainab Bello', 'email' => 'zainab@example.test'])->save();

    $byName = staffMember(StaffType::NON_TEACHING);
    $byName->user->forceFill(['name' => 'Bright Amina', 'email' => 'bright@example.test'])->save();

    $byEmail = staffMember(StaffType::TEACHING);
    $byEmail->user->forceFill(['name' => 'Chinedu Obi', 'email' => 'uniquehandle@example.test'])->save();

    staffMember(StaffType::TEACHING); // unrelated

    $ids = fn (string $q): array => asUser(userWithRole(Role::ADMIN))
        ->getJson("/api/v1/staff?search={$q}")
        ->assertOk()
        ->json('data.*.id');

    // The person's identity lives on users, not staff, so a search that only looked at
    // staff would find nothing for "Amina".
    expect($ids('STA-77'))->toBe([$byNumber->id])
        ->and($ids('Bright'))->toBe([$byName->id])
        ->and($ids('uniquehandle'))->toBe([$byEmail->id])
        ->and($ids('amina'))->toBe([$byName->id]);
});

it('filters by staff type and by employment status', function (): void {
    $teachingActive = staffMember(StaffType::TEACHING);
    staffMember(StaffType::NON_TEACHING);
    $teachingInactive = staffMember(StaffType::TEACHING, ['status' => EmploymentStatus::INACTIVE]);

    $token = loginAs(userWithRole(Role::ADMIN));

    // staff_type is a classification, status is employment, and the two combine: two
    // teaching staff of whom one is not currently working.
    expect(withToken($token)->getJson('/api/v1/staff?staff_type=TEACHING')->json('data.*.id'))
        ->toEqualCanonicalizing([$teachingActive->id, $teachingInactive->id]);

    expect(withToken($token)->getJson('/api/v1/staff?staff_type=NON_TEACHING')->json('data.*.id'))
        ->toHaveCount(1);

    expect(withToken($token)->getJson('/api/v1/staff?status=INACTIVE')->json('data.*.id'))
        ->toBe([$teachingInactive->id]);

    expect(withToken($token)->getJson('/api/v1/staff?staff_type=TEACHING&status=INACTIVE')->json('data.*.id'))
        ->toBe([$teachingInactive->id]);

    expect(withToken($token)->getJson('/api/v1/staff?status=TERMINATED')->json('data'))
        ->toBe([]);
});

it('filters by the account status, which is a different fact from employment', function (): void {
    $suspended = staffMember(StaffType::TEACHING);
    $suspended->user->forceFill(['status' => UserStatus::SUSPENDED])->save();
    staffMember(StaffType::TEACHING);

    asUser(userWithRole(Role::ADMIN))
        ->getJson('/api/v1/staff?account_status=SUSPENDED')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $suspended->id)
        // An ACTIVE account and ACTIVE employment, side by side, so the difference is
        // legible rather than assumed.
        ->assertJsonPath('data.0.status', 'ACTIVE')
        ->assertJsonPath('data.0.account_status', 'SUSPENDED');
});

it('reports that every staff record has an account rather than pretending otherwise', function (): void {
    $staff = staffMember(StaffType::TEACHING);

    $token = loginAs(userWithRole(Role::ADMIN));

    // staff.user_id is NOT NULL and UNIQUE, so has_account cannot be selective. It is
    // implemented as an honest existence check on the linked account rather than being
    // dropped, and these two assertions are what pin that schema constraint down in
    // executable form.
    expect(withToken($token)->getJson('/api/v1/staff?has_account=true')->json('data.0.id'))
        ->toBe($staff->id);

    expect(withToken($token)->getJson('/api/v1/staff?has_account=false')->json('data'))
        ->toBe([]);
});

it('accepts the four obvious spellings of a boolean filter and refuses the rest', function (): void {
    staffMember(StaffType::TEACHING);

    $token = loginAs(userWithRole(Role::ADMIN));

    foreach (['1', '0', 'true', 'false'] as $value) {
        withToken($token)->getJson("/api/v1/staff?has_account={$value}")->assertOk();
    }

    // "false" is a non-empty string and therefore truthy, which is exactly the bug the
    // shared list request exists to stop: a filter spelled "false" must not behave like
    // one spelled "true".
    expect(withToken($token)->getJson('/api/v1/staff?has_account=false')->json('data'))->toBe([])
        ->and(withToken($token)->getJson('/api/v1/staff?has_account=true')->json('data'))->toHaveCount(1);

    withToken($token)
        ->getJson('/api/v1/staff?has_account=maybe')
        ->assertStatus(422)
        ->assertJsonValidationErrors('has_account');
});

it('caps the page size and defaults it to 15', function (): void {
    staffMember(StaffType::TEACHING);

    $token = loginAs(userWithRole(Role::ADMIN));

    withToken($token)->getJson('/api/v1/staff')->assertOk()->assertJsonPath('meta.per_page', 15);
    withToken($token)->getJson('/api/v1/staff?per_page=100')->assertOk()->assertJsonPath('meta.per_page', 100);

    foreach (['1000000', '0', '-5', 'abc'] as $bad) {
        withToken($token)
            ->getJson("/api/v1/staff?per_page={$bad}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('per_page');
    }
});

it('rejects an unknown filter value and names the permitted values', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    withToken($token)
        ->getJson('/api/v1/staff?status=NONSENSE')
        ->assertStatus(422)
        ->assertJsonValidationErrors('status')
        ->assertJsonFragment(['The status must be one of: ACTIVE, INACTIVE, TERMINATED.']);

    withToken($token)
        ->getJson('/api/v1/staff?staff_type=TEACHER')
        ->assertStatus(422)
        ->assertJsonValidationErrors('staff_type')
        ->assertJsonFragment(['The staff type must be one of: TEACHING, NON_TEACHING.']);

    withToken($token)
        ->getJson('/api/v1/staff?account_status=ASLEEP')
        ->assertStatus(422)
        ->assertJsonValidationErrors('account_status')
        ->assertJsonFragment(['The account status must be one of: ACTIVE, INACTIVE, SUSPENDED.']);
});

it('orders by most recently appointed, with undated records last and a stable tiebreak', function (): void {
    $undated = staffMember(StaffType::TEACHING);
    $undated->user->forceFill(['name' => 'Zoe Undated'])->save();

    $older = staffMember(StaffType::TEACHING, ['employment_date' => '2020-01-01']);
    $older->user->forceFill(['name' => 'Yan Oldest'])->save();

    $newer = staffMember(StaffType::TEACHING, ['employment_date' => '2024-01-01']);
    $newer->user->forceFill(['name' => 'Amy Newest'])->save();

    // A null employment date is the absence of one, not the newest date, so it must not
    // lead page one forever.
    $ids = asUser(userWithRole(Role::ADMIN))->getJson('/api/v1/staff')->assertOk()->json('data.*.id');

    expect($ids)->toBe([$newer->id, $older->id, $undated->id]);
});

it('keeps the filters in the paging links', function (): void {
    staffMember(StaffType::TEACHING);
    staffMember(StaffType::NON_TEACHING);

    $response = asUser(userWithRole(Role::ADMIN))
        ->getJson('/api/v1/staff?staff_type=TEACHING')
        ->assertOk();

    expect($response->json('links.next') ?? $response->json('links.first'))
        ->toContain('staff_type=TEACHING');
});

/*
|--------------------------------------------------------------------------
| The authorization matrix
|--------------------------------------------------------------------------
*/

it('grants exactly the documented rights to each role', function (): void {
    $expected = [
        // Role               view  create  update  activate  deactivate
        'SUPER_ADMIN' => [200, 201, 200, 200, 200],
        'ADMIN' => [200, 201, 200, 200, 200],
        // Registrar builds the staff establishment, but ending an employment while the
        // account is still open is held back.
        'REGISTRAR' => [200, 201, 200, 403, 403],
        'STAFF' => [403, 403, 403, 403, 403],
        'STUDENT' => [403, 403, 403, 403, 403],
    ];

    foreach ($expected as $roleName => [$view, $create, $update, $activate, $deactivate]) {
        $staff = staffMember(StaffType::TEACHING);
        $user = userWithRole(Role::from($roleName));

        // One login per role, then five requests with that token: asUser() posts to the
        // real login route and its limiter allows five attempts a minute per address.
        $token = loginAs($user);

        withToken($token)->getJson("/api/v1/staff/{$staff->id}")->assertStatus($view);

        withToken($token)->postJson('/api/v1/staff', staffCreatePayload([
            'email' => strtolower($roleName).'-created@example.test',
        ]))->assertStatus($create);

        withToken($token)->putJson("/api/v1/staff/{$staff->id}", staffUpdatePayload([
            'designation' => 'Matrix Check',
        ]))->assertStatus($update);

        withToken($token)->postJson("/api/v1/staff/{$staff->id}/activate")->assertStatus($activate);
        withToken($token)->postJson("/api/v1/staff/{$staff->id}/deactivate")->assertStatus($deactivate);
    }
});

it('lets a single staff member hold a staff permission without a new role', function (): void {
    // The reason permissions exist rather than role checks: two STAFF users can hold
    // different rights without inventing a sixth role.
    $staffUser = userWithRole(Role::STAFF);
    grantPermission($staffUser, 'staff.view');

    staffMember(StaffType::TEACHING);

    asUser($staffUser)->getJson('/api/v1/staff')->assertOk();

    // The grant is scoped to the view, not a promotion.
    asUser($staffUser)->postJson('/api/v1/staff', staffCreatePayload())->assertStatus(403);
});

/*
|--------------------------------------------------------------------------
| The unique index still holds
|--------------------------------------------------------------------------
*/

it('refuses a duplicate staff number at the database level', function (): void {
    staffMember(StaffType::TEACHING, ['staff_number' => 'STA-55']);

    // Not reachable through the API, which pre-checks uniqueness and answers 422. The
    // index is the last line of defence against a race between two concurrent creates,
    // and it is asserted here so it is not quietly dropped.
    expect(fn () => Staff::factory()->create(['staff_number' => 'STA-55']))
        ->toThrow(UniqueConstraintViolationException::class);
});

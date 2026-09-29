<?php

use App\Enums\CatalogStatus;
use App\Enums\Role;
use App\Enums\StaffType;
use App\Enums\UserStatus;
use App\Models\Permission;
use App\Models\Role as RoleModel;
use App\Models\Subject;

/*
|--------------------------------------------------------------------------
| Subject authorization, filters and exposure (Module 07)
|--------------------------------------------------------------------------
*/

/*
| Authorization
*/

it('refuses subjects to an unauthenticated caller', function (): void {
    catalogSubject();

    test()->getJson('/api/v1/subjects')->assertUnauthorized();
    test()->postJson('/api/v1/subjects', subjectCreatePayload())->assertUnauthorized();
});

it('refuses subjects to a user with no subjects permission', function (): void {
    $teacher = userWithRole(Role::STAFF);
    $token = loginAs($teacher);

    withToken($token)->getJson('/api/v1/subjects')->assertForbidden();
    withToken($token)->postJson('/api/v1/subjects', subjectCreatePayload())->assertForbidden();
});

it('refuses a non-teaching staff member the same way as a teaching one', function (): void {
    // The brief's own caution: do not give STAFF subject-management access merely because
    // they are teaching staff, and non-teaching staff must not fare any differently either -
    // the permission gate does not distinguish StaffType at all.
    $staffMember = staffMember(StaffType::NON_TEACHING);
    $token = loginAs($staffMember->user);

    withToken($token)->postJson('/api/v1/subjects', subjectCreatePayload())->assertForbidden();
});

it('refuses subjects to a suspended account, even with a token minted before the suspension', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);

    $admin->forceFill(['status' => UserStatus::SUSPENDED])->save();
    forgetResolvedUser();

    withToken($token)->getJson('/api/v1/subjects')->assertForbidden();
});

it('grants subjects to super admin, admin and registrar', function (): void {
    foreach ([Role::SUPER_ADMIN, Role::ADMIN, Role::REGISTRAR] as $role) {
        $token = loginAs(userWithRole($role));

        withToken($token)->getJson('/api/v1/subjects')->assertOk();
        withToken($token)->postJson('/api/v1/subjects', subjectCreatePayload())->assertCreated();
    }
});

it('withholds delete from registrar, matching the class-structure permission split', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $subject = catalogSubject();

    withToken($token)->deleteJson("/api/v1/subjects/{$subject->id}")->assertForbidden();

    expect(Subject::query()->whereKey($subject->id)->exists())->toBeTrue();
});

it('grants delete to admin and super admin', function (): void {
    foreach ([Role::SUPER_ADMIN, Role::ADMIN] as $role) {
        $subject = catalogSubject();
        $token = loginAs(userWithRole($role));

        withToken($token)->deleteJson("/api/v1/subjects/{$subject->id}")->assertOk();
    }
});

it('requires a specific permission per action rather than one blanket grant', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $subject = catalogSubject();

    $admin->role->permissions()->detach(
        Permission::query()->where('name', 'subjects.create')->value('id')
    );

    $token = loginAs($admin);

    withToken($token)->getJson('/api/v1/subjects')->assertOk();
    withToken($token)->postJson('/api/v1/subjects', subjectCreatePayload())->assertForbidden();
    withToken($token)->putJson("/api/v1/subjects/{$subject->id}", subjectUpdatePayload())->assertOk();
});

it('seeds the permissions this module owns and no others', function (): void {
    $names = Permission::query()->where('name', 'like', 'subjects.%')->pluck('name')->all();

    expect($names)->toEqualCanonicalizing(['subjects.view', 'subjects.create', 'subjects.update', 'subjects.delete']);
});

it('adds the module permissions without revoking the earlier modules', function (): void {
    $admin = RoleModel::query()->where('name', Role::ADMIN->value)->firstOrFail();
    $held = $admin->permissions()->pluck('name')->all();

    expect($held)->toContain('school.view')          // Module 01/02
        ->toContain('staff.view')                    // Module 03
        ->toContain('students.view')                 // Module 04
        ->toContain('admissions.view')                // Module 05
        ->toContain('enrollments.view')               // Module 06
        ->toContain('subjects.view');                 // Module 07
});

/*
| Filters
*/

it('finds a subject by name', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    $target = catalogSubject(['name' => 'Further Mathematics']);
    catalogSubject(['name' => 'Biology']);

    withToken($token)->getJson('/api/v1/subjects?search=further')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $target->id);
});

it('finds a subject by an exact code', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    $target = catalogSubject(['code' => 'MAT']);
    catalogSubject(['code' => 'BIO']);

    withToken($token)->getJson('/api/v1/subjects?code=mat')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $target->id);
});

it('filters by status', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    catalogSubject(['status' => CatalogStatus::ACTIVE]);
    $inactive = catalogSubject(['status' => CatalogStatus::INACTIVE]);

    withToken($token)->getJson('/api/v1/subjects?status=INACTIVE')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $inactive->id);
});

it('filters to only active subjects with active_only', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    $active = catalogSubject(['status' => CatalogStatus::ACTIVE]);
    catalogSubject(['status' => CatalogStatus::ARCHIVED]);

    withToken($token)->getJson('/api/v1/subjects?active_only=true')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $active->id);
});

it('rejects a status filter value the enum does not define', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    withToken($token)->getJson('/api/v1/subjects?status=DRAFT')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');
});

it('pages the catalogue and caps the page size', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    Subject::factory()->count(25)->create();

    $first = withToken($token)->getJson('/api/v1/subjects?per_page=10&page=1');
    $first->assertOk()->assertJsonCount(10, 'data');

    expect($first->json('meta.total'))->toBe(25);

    withToken($token)->getJson('/api/v1/subjects?per_page=500')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('per_page');
});

it('rejects invalid pagination', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    withToken($token)->getJson('/api/v1/subjects?per_page=0')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('per_page');
});

/*
| What the API will not do or show
*/

it('ignores a mass-assignment attempt to inject an id or timestamps', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    $response = withToken($token)->postJson('/api/v1/subjects', subjectCreatePayload([
        'id' => 999999,
        'created_at' => '2000-01-01T00:00:00Z',
    ]));

    $response->assertCreated();

    expect($response->json('data.id'))->not->toBe(999999)
        ->and(Subject::query()->sole()->created_at->year)->not->toBe(2000);
});

it('lists subjects through the same envelope as every other list in the API', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    catalogSubject();

    withToken($token)->getJson('/api/v1/subjects')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [['id', 'name', 'code', 'sort_order', 'status']],
            'meta' => ['current_page', 'per_page', 'total', 'last_page'],
        ]);
});

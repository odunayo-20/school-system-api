<?php

use App\Enums\Role;
use App\Enums\StaffType;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

/*
 * Teaching and non-teaching staff are a CLASSIFICATION of STAFF, not separate
 * authentication roles. There is exactly one STAFF role, one login endpoint and one
 * staff_type column.
 */

test('there is no separate authentication role for teaching or non teaching staff', function () {
    expect(Role::values())->toBe(['SUPER_ADMIN', 'ADMIN', 'REGISTRAR', 'STAFF', 'STUDENT'])
        ->and(Role::values())->not->toContain('TEACHING_STAFF')
        ->and(Role::values())->not->toContain('NON_TEACHING_STAFF');
});

test('teaching and non teaching staff can both exist under the single staff role', function () {
    $teaching = userWithRole(Role::STAFF);
    $nonTeaching = userWithRole(Role::STAFF);

    Staff::factory()->create(['user_id' => $teaching->id, 'staff_type' => StaffType::TEACHING]);
    Staff::factory()->create(['user_id' => $nonTeaching->id, 'staff_type' => StaffType::NON_TEACHING]);

    expect($teaching->fresh()->roleEnum())->toBe(Role::STAFF)
        ->and($teaching->fresh()->staffType())->toBe(StaffType::TEACHING)
        ->and($nonTeaching->fresh()->roleEnum())->toBe(Role::STAFF)
        ->and($nonTeaching->fresh()->staffType())->toBe(StaffType::NON_TEACHING);

    $this->assertDatabaseCount('staff', 2);
});

test('both staff types authenticate through the same login endpoint', function () {
    $teaching = userWithRole(Role::STAFF);
    $nonTeaching = userWithRole(Role::STAFF);

    Staff::factory()->create(['user_id' => $teaching->id, 'staff_type' => StaffType::TEACHING]);
    Staff::factory()->create(['user_id' => $nonTeaching->id, 'staff_type' => StaffType::NON_TEACHING]);

    $this->postJson('/api/v1/auth/login', ['email' => $teaching->email, 'password' => 'password'])
        ->assertOk()
        ->assertJsonPath('data.user.role', Role::STAFF->value)
        ->assertJsonPath('data.user.staff_type', StaffType::TEACHING->value);

    $this->postJson('/api/v1/auth/login', ['email' => $nonTeaching->email, 'password' => 'password'])
        ->assertOk()
        ->assertJsonPath('data.user.role', Role::STAFF->value)
        ->assertJsonPath('data.user.staff_type', StaffType::NON_TEACHING->value);
});

test('two staff users of different types can hold different permissions', function () {
    $teaching = userWithRole(Role::STAFF);
    $nonTeaching = userWithRole(Role::STAFF);

    Staff::factory()->create(['user_id' => $teaching->id, 'staff_type' => StaffType::TEACHING]);
    Staff::factory()->create(['user_id' => $nonTeaching->id, 'staff_type' => StaffType::NON_TEACHING]);

    // Stands in for the Module that will own results.* / attendance.* permissions.
    grantPermission($teaching, 'results.enter');
    grantPermission($nonTeaching, 'students.view');

    expect($teaching->hasPermission('results.enter'))->toBeTrue()
        ->and($teaching->hasPermission('students.view'))->toBeFalse()
        ->and($nonTeaching->hasPermission('students.view'))->toBeTrue()
        ->and($nonTeaching->hasPermission('results.enter'))->toBeFalse();
});

test('the users table carries no staff specific columns', function () {
    expect(Schema::hasColumns('users', ['id', 'name', 'email', 'password', 'status',
        'email_verified_at', 'last_login_at', 'remember_token', 'created_at', 'updated_at',
        'role_id']))->toBeTrue();

    expect(Schema::hasColumn('users', 'staff_type'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'staff_number'))->toBeFalse();
});

test('a user has at most one staff record', function () {
    $user = userWithRole(Role::STAFF);

    Staff::factory()->create(['user_id' => $user->id]);

    expect(fn () => Staff::factory()->create(['user_id' => $user->id]))
        ->toThrow(QueryException::class);
});

test('a staff record is removed when its user is deleted', function () {
    $user = userWithRole(Role::STAFF);
    Staff::factory()->create(['user_id' => $user->id]);

    $user->delete();

    $this->assertDatabaseCount('staff', 0);
});

test('a non staff user has no staff type', function () {
    $student = userWithRole(Role::STUDENT);

    expect($student->staffType())->toBeNull()
        ->and($student->isStaff())->toBeFalse();

    $admin = userWithRole(Role::ADMIN);

    expect($admin->staffType())->toBeNull();
});

test('the factory produces both staff variants', function () {
    expect(User::factory()->teachingStaff()->create()->fresh()->staffType())->toBe(StaffType::TEACHING)
        ->and(User::factory()->nonTeachingStaff()->create()->fresh()->staffType())->toBe(StaffType::NON_TEACHING);
});

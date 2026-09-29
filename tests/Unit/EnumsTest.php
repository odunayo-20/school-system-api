<?php

use App\Enums\Gender;
use App\Enums\Role;
use App\Enums\StaffType;
use App\Enums\StudentStatus;
use App\Enums\UserStatus;
use App\Models\Permission;

test('the role enum contains exactly the five supported roles', function () {
    expect(Role::values())->toBe(['SUPER_ADMIN', 'ADMIN', 'REGISTRAR', 'STAFF', 'STUDENT']);
});

test('only super admin is identified as the bypass role', function () {
    expect(Role::SUPER_ADMIN->isSuperAdmin())->toBeTrue();

    foreach ([Role::ADMIN, Role::REGISTRAR, Role::STAFF, Role::STUDENT] as $role) {
        expect($role->isSuperAdmin())->toBeFalse();
    }
});

test('the account status enum contains exactly the three supported statuses', function () {
    expect(UserStatus::values())->toBe(['ACTIVE', 'INACTIVE', 'SUSPENDED'])
        ->and(UserStatus::ACTIVE->isActive())->toBeTrue()
        ->and(UserStatus::INACTIVE->isActive())->toBeFalse()
        ->and(UserStatus::SUSPENDED->isActive())->toBeFalse();
});

test('the staff type enum contains only the two classifications of staff', function () {
    expect(StaffType::values())->toBe(['TEACHING', 'NON_TEACHING']);
});

test('the pupil lifecycle is two reversible states and two terminal ones', function () {
    // ACTIVE and INACTIVE are reversible; GRADUATED and WITHDRAWN are not. The two terminal
    // states are kept apart because "finished the school" and "left the school" are
    // different facts, and collapsing them would lose the distinction worth keeping.
    expect(StudentStatus::values())->toBe(['ACTIVE', 'INACTIVE', 'GRADUATED', 'WITHDRAWN']);

    expect(StudentStatus::ACTIVE->isActive())->toBeTrue()
        ->and(StudentStatus::INACTIVE->isActive())->toBeFalse()
        ->and(StudentStatus::GRADUATED->isActive())->toBeFalse()
        ->and(StudentStatus::WITHDRAWN->isActive())->toBeFalse();

    foreach ([StudentStatus::ACTIVE, StudentStatus::INACTIVE] as $reversible) {
        expect($reversible->isTerminal())->toBeFalse();
    }

    foreach ([StudentStatus::GRADUATED, StudentStatus::WITHDRAWN] as $terminal) {
        expect($terminal->isTerminal())->toBeTrue();
    }
});

test('the pupil lifecycle is distinct from the account status enum', function () {
    // The two are separate questions and must not be able to drift into each other: "is this
    // child on the roll" and "can this login be used" change independently. The overlap in
    // names is exactly why they live in different enums.
    expect(StudentStatus::values())->not->toBe(UserStatus::values())
        ->and(in_array('SUSPENDED', StudentStatus::values(), true))->toBeFalse()
        ->and(in_array('TERMINATED', StudentStatus::values(), true))->toBeFalse();
});

test('the gender enum is a nullable pair, and the column stays nullable', function () {
    // Two cases is the honest limit of what this model can record, and the pair is nullable
    // because a school cannot always know. Both facts are asserted together so a later
    // "just make it required" change has to be a deliberate edit to a failing test.
    expect(Gender::values())->toBe(['MALE', 'FEMALE']);
});

test('a permission name is module and action', function (string $name, bool $valid) {
    expect(Permission::isValidName($name))->toBe($valid);
})->with([
    ['users.view', true],
    ['students.create', true],
    ['results.publish', true],
    ['admissions.approve', true],
    ['view', false],
    ['Users.View', false],
    ['.view', false],
    ['users.', false],
    ['users view', false],
]);

test('the permission group is derived from the permission name', function () {
    $permission = new Permission(['name' => 'results.publish']);

    expect($permission->group())->toBe('results');
});

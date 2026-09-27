<?php

use App\Enums\Role;
use App\Enums\StaffType;
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

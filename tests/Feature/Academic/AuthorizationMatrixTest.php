<?php

use App\Enums\Role;
use App\Models\Permission;
use App\Models\Role as RoleModel;
use Database\Seeders\AcademicPermissionSeeder;

/*
|--------------------------------------------------------------------------
| Authorization matrix
|--------------------------------------------------------------------------
|
| One place that states, as data, who may do what. Every other test in this module asserts
| the behaviour of a single rule; this one asserts the whole grant table at once, so a
| permission added to the seeder and forgotten in a role is caught here rather than
| discovered in production.
|
| The expectations are written out in full rather than read from the seeder. Generating the
| expectation from the same array the seeder grants from would assert only that the seeder
| agrees with itself, which it does by construction.
|
*/

function academicPermissionMatrix(): array
{
    return [
        'school.view' => [
            Role::SUPER_ADMIN->value => true,
            Role::ADMIN->value => true,
            Role::REGISTRAR->value => true,
            Role::STAFF->value => true,
            Role::STUDENT->value => false,
        ],
        'school.update' => [
            Role::SUPER_ADMIN->value => true,
            Role::ADMIN->value => true,
            Role::REGISTRAR->value => false,
            Role::STAFF->value => false,
            Role::STUDENT->value => false,
        ],
        'academic_sessions.view' => [
            Role::SUPER_ADMIN->value => true,
            Role::ADMIN->value => true,
            Role::REGISTRAR->value => true,
            Role::STAFF->value => true,
            Role::STUDENT->value => false,
        ],
        'academic_sessions.create' => [
            Role::SUPER_ADMIN->value => true,
            Role::ADMIN->value => true,
            // Which year the school is in is an administrative decision, not a registrar's.
            Role::REGISTRAR->value => false,
            Role::STAFF->value => false,
            Role::STUDENT->value => false,
        ],
        'academic_sessions.update' => [
            Role::SUPER_ADMIN->value => true,
            Role::ADMIN->value => true,
            Role::REGISTRAR->value => false,
            Role::STAFF->value => false,
            Role::STUDENT->value => false,
        ],
        'academic_sessions.activate' => [
            Role::SUPER_ADMIN->value => true,
            Role::ADMIN->value => true,
            Role::REGISTRAR->value => false,
            Role::STAFF->value => false,
            Role::STUDENT->value => false,
        ],
        'academic_sessions.delete' => [
            Role::SUPER_ADMIN->value => true,
            // Erasing academic history is irreversible and later modules have already
            // referenced sessions, so it is the super administrator's alone.
            Role::ADMIN->value => false,
            Role::REGISTRAR->value => false,
            Role::STAFF->value => false,
            Role::STUDENT->value => false,
        ],
        'terms.view' => [
            Role::SUPER_ADMIN->value => true,
            Role::ADMIN->value => true,
            Role::REGISTRAR->value => true,
            Role::STAFF->value => true,
            Role::STUDENT->value => false,
        ],
        'terms.create' => [
            Role::SUPER_ADMIN->value => true,
            Role::ADMIN->value => true,
            Role::REGISTRAR->value => true,
            Role::STAFF->value => false,
            Role::STUDENT->value => false,
        ],
        'terms.update' => [
            Role::SUPER_ADMIN->value => true,
            Role::ADMIN->value => true,
            Role::REGISTRAR->value => true,
            Role::STAFF->value => false,
            Role::STUDENT->value => false,
        ],
        'terms.activate' => [
            Role::SUPER_ADMIN->value => true,
            Role::ADMIN->value => true,
            // A registrar amends the calendar but does not decide which term is running.
            Role::REGISTRAR->value => false,
            Role::STAFF->value => false,
            Role::STUDENT->value => false,
        ],
        'terms.delete' => [
            Role::SUPER_ADMIN->value => true,
            Role::ADMIN->value => false,
            Role::REGISTRAR->value => false,
            Role::STAFF->value => false,
            Role::STUDENT->value => false,
        ],
        'class_levels.view' => [
            Role::SUPER_ADMIN->value => true,
            Role::ADMIN->value => true,
            Role::REGISTRAR->value => true,
            Role::STAFF->value => true,
            Role::STUDENT->value => false,
        ],
        'class_levels.create' => [
            Role::SUPER_ADMIN->value => true,
            Role::ADMIN->value => true,
            Role::REGISTRAR->value => true,
            Role::STAFF->value => false,
            Role::STUDENT->value => false,
        ],
        'class_levels.update' => [
            Role::SUPER_ADMIN->value => true,
            Role::ADMIN->value => true,
            Role::REGISTRAR->value => true,
            Role::STAFF->value => false,
            Role::STUDENT->value => false,
        ],
        'class_levels.delete' => [
            Role::SUPER_ADMIN->value => true,
            Role::ADMIN->value => true,
            Role::REGISTRAR->value => false,
            Role::STAFF->value => false,
            Role::STUDENT->value => false,
        ],
        'classes.view' => [
            Role::SUPER_ADMIN->value => true,
            Role::ADMIN->value => true,
            Role::REGISTRAR->value => true,
            Role::STAFF->value => true,
            Role::STUDENT->value => false,
        ],
        'classes.create' => [
            Role::SUPER_ADMIN->value => true,
            Role::ADMIN->value => true,
            Role::REGISTRAR->value => true,
            Role::STAFF->value => false,
            Role::STUDENT->value => false,
        ],
        'classes.update' => [
            Role::SUPER_ADMIN->value => true,
            Role::ADMIN->value => true,
            Role::REGISTRAR->value => true,
            Role::STAFF->value => false,
            Role::STUDENT->value => false,
        ],
        'classes.delete' => [
            Role::SUPER_ADMIN->value => true,
            Role::ADMIN->value => true,
            Role::REGISTRAR->value => false,
            Role::STAFF->value => false,
            Role::STUDENT->value => false,
        ],
        'sections.view' => [
            Role::SUPER_ADMIN->value => true,
            Role::ADMIN->value => true,
            Role::REGISTRAR->value => true,
            Role::STAFF->value => true,
            Role::STUDENT->value => false,
        ],
        'sections.create' => [
            Role::SUPER_ADMIN->value => true,
            Role::ADMIN->value => true,
            Role::REGISTRAR->value => true,
            Role::STAFF->value => false,
            Role::STUDENT->value => false,
        ],
        'sections.update' => [
            Role::SUPER_ADMIN->value => true,
            Role::ADMIN->value => true,
            Role::REGISTRAR->value => true,
            Role::STAFF->value => false,
            Role::STUDENT->value => false,
        ],
        'sections.delete' => [
            Role::SUPER_ADMIN->value => true,
            Role::ADMIN->value => true,
            Role::REGISTRAR->value => false,
            Role::STAFF->value => false,
            Role::STUDENT->value => false,
        ],
    ];
}

test('every academic permission in the matrix exists exactly once', function () {
    $expected = collect(array_keys(academicPermissionMatrix()))->sort()->values()->all();

    // Compared as a set, not a list: the order the seeder declares its permissions in is an
    // implementation detail, and asserting on it would fail on a harmless reorder while
    // saying nothing about whether the grants are right.
    $seeded = collect(AcademicPermissionSeeder::names())->sort()->values()->all();

    expect($seeded)->toBe($expected);

    foreach ($expected as $name) {
        expect(Permission::query()->where('name', $name)->count())->toBe(1);
    }
});

test('each role holds exactly the academic permissions the matrix says', function (string $role, array $expectedNames) {
    $model = RoleModel::query()->where('name', $role)->firstOrFail();

    $granted = $model->permissions()
        ->whereIn('name', AcademicPermissionSeeder::names())
        ->pluck('name')
        ->sort()
        ->values()
        ->all();

    $expected = collect($expectedNames)->sort()->values()->all();

    expect($granted)->toBe($expected);
})->with(function () {
    $matrix = academicPermissionMatrix();
    $cases = [];

    foreach ([Role::SUPER_ADMIN, Role::ADMIN, Role::REGISTRAR, Role::STAFF, Role::STUDENT] as $role) {
        // Named, because a bare list would bind only to the first parameter and the test
        // would fail on its own signature rather than on the grants.
        $cases[$role->value] = [
            'role' => $role->value,
            'expectedNames' => collect($matrix)
                ->filter(fn (array $roles): bool => $roles[$role->value] ?? false)
                ->keys()
                ->all(),
        ];
    }

    return $cases;
});

test('a student holds no academic permission at all', function () {
    $student = userWithRole(Role::STUDENT);

    // A student's own session and term arrive with the student module, scoped to their own
    // enrollment. Nothing here is school wide, so a student is granted none of it.
    foreach (AcademicPermissionSeeder::names() as $name) {
        expect($student->hasPermission($name))->toBeFalse();
    }
});

test('a super administrator passes every permission check', function () {
    $superAdmin = userWithRole(Role::SUPER_ADMIN);

    foreach (AcademicPermissionSeeder::names() as $name) {
        expect($superAdmin->hasPermission($name))->toBeTrue();
    }
});

test('the super admin bypass survives a permission that was never seeded', function () {
    $superAdmin = userWithRole(Role::SUPER_ADMIN);

    // The bypass is centralised in a Gate::before callback rather than repeated per
    // controller, so it applies to abilities that do not exist yet - which is what a later
    // module relies on before its own seeder has run anywhere.
    expect($superAdmin->hasPermission('some.later_module_permission'))->toBeTrue();
});

test('module 01 grants are preserved alongside the academic ones', function () {
    // AcademicPermissionSeeder grants with syncWithoutDetaching precisely so it cannot
    // revoke what Module 01's sync() already granted. If a role lost a Module 01 permission
    // here, the two seeders would no longer be independent.
    $admin = RoleModel::query()->where('name', Role::ADMIN->value)->firstOrFail();

    expect($admin->permissions()->where('name', 'users.view')->exists())->toBeTrue();

    $total = $admin->permissions()->count();
    $academic = $admin->permissions()->whereIn('name', AcademicPermissionSeeder::names())->count();

    expect($total)->toBeGreaterThan($academic);
});

test('re-running the academic seeder does not revoke module 01 grants', function () {
    $before = RoleModel::query()->where('name', Role::ADMIN->value)->firstOrFail()
        ->permissions()->pluck('name')->sort()->values()->all();

    $this->seed(AcademicPermissionSeeder::class);

    $after = RoleModel::query()->where('name', Role::ADMIN->value)->firstOrFail()
        ->permissions()->pluck('name')->sort()->values()->all();

    expect($after)->toBe($before);
});

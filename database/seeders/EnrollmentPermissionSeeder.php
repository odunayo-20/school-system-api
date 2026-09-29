<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Permission;
use App\Models\Role as RoleModel;
use Illuminate\Database\Seeder;

/**
 * Module 06 seeds the permissions for enrollment management and grants them to the roles that
 * already exist.
 *
 * Same conventions as every module seeder before it: a SEPARATE seeder from Module 01's
 * PermissionSeeder, granting with syncWithoutDetaching() rather than sync() so it adds to a
 * role's permission set instead of replacing it, and updateOrCreate() on the permission name
 * so re-seeding is safe.
 *
 * ORDERING IS LOAD-BEARING, exactly as for every seeder above: this must run after
 * PermissionSeeder in both DatabaseSeeder and tests\TestCase, or Module 01's sync() would
 * revoke every grant made here.
 */
class EnrollmentPermissionSeeder extends Seeder
{
    /**
     * @var array<string, array{label: string, description: string}>
     */
    protected array $permissions = [
        'enrollments.view' => [
            'label' => 'View enrollments',
            'description' => 'List and read academic placement records.',
        ],
        'enrollments.create' => [
            'label' => 'Create enrollments',
            'description' => 'Place a student in a class and section for an academic session.',
        ],
        'enrollments.update' => [
            'label' => 'Update enrollments',
            'description' => 'Amend an active enrollment\'s date and notes.',
        ],
        'enrollments.withdraw' => [
            'label' => 'Withdraw enrollments',
            'description' => 'Record that a student left a placement before the session ended.',
        ],
        'enrollments.cancel' => [
            'label' => 'Cancel enrollments',
            'description' => 'Void an enrollment that should not have been created.',
        ],
    ];

    /**
     * Five permissions, one per real operation. There is no enrollments.delete: no destroy()
     * is registered, and a permission for an operation that cannot be performed is a grant
     * with no meaning - the same reasoning that left staff.delete, students.delete and
     * admissions.delete out.
     *
     * withdraw and cancel are separated from update and from each other, following Module 03's
     * activate/deactivate split and Module 05's admit/reject/withdraw split: each is a state
     * transition with a permanent effect, so each can be granted independently of the ability
     * to make an ordinary amend.
     *
     * @var array<string, list<string>>
     */
    protected array $rolePermissions = [
        Role::SUPER_ADMIN->value => [
            'enrollments.view', 'enrollments.create', 'enrollments.update',
            'enrollments.withdraw', 'enrollments.cancel',
        ],
        // An administrator runs the school day to day, and academic placement is part of
        // that.
        Role::ADMIN->value => [
            'enrollments.view', 'enrollments.create', 'enrollments.update',
            'enrollments.withdraw', 'enrollments.cancel',
        ],
        // RoleSeeder's own words: "Handles admissions, enrollment and student records" -
        // enrollment is named explicitly, not inferred. The same reasoning Module 05 used to
        // grant REGISTRAR the full admission workflow applies here: a registrar who could
        // create a placement but not correct or void one would be unable to do the job the
        // role exists for.
        Role::REGISTRAR->value => [
            'enrollments.view', 'enrollments.create', 'enrollments.update',
            'enrollments.withdraw', 'enrollments.cancel',
        ],
        // A teacher's need to know which class a pupil is in is real, but a licence to browse
        // and place EVERY student in the school is not the same need. A scoped "my class
        // roster" answer belongs to a future module (Results/Attendance), not a broad grant
        // of enrollments.view here - the identical restraint Module 04 and Module 05 both
        // applied to STAFF.
        Role::STAFF->value => [],
        // A student holds nothing over the enrollment table directly. Module 01's profile.*
        // pair stays the only self-service surface; a "my enrollment history" endpoint, if
        // ever built, is additive and does not require broad enrollments.view either.
        Role::STUDENT->value => [],
    ];

    public function run(): void
    {
        $permissions = [];

        foreach ($this->permissions as $name => $attributes) {
            $permissions[$name] = Permission::query()->updateOrCreate(['name' => $name], $attributes);
        }

        foreach ($this->rolePermissions as $roleName => $names) {
            $role = RoleModel::query()->where('name', $roleName)->first();

            if (! $role) {
                continue;
            }

            $role->permissions()->syncWithoutDetaching(
                collect($names)->map(fn (string $name): int => $permissions[$name]->getKey())->all()
            );
        }
    }

    /**
     * The permission names this module owns, for tests and documentation.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys((new self)->permissions);
    }
}

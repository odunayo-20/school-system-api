<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Permission;
use App\Models\Role as RoleModel;
use Illuminate\Database\Seeder;

/**
 * Module 04 seeds the permissions for student management and grants them to the roles that
 * already exist.
 *
 * Same two deliberate choices as Module 02's AcademicPermissionSeeder and Module 03's
 * StaffPermissionSeeder, for the same reasons:
 *
 * 1. This is a SEPARATE seeder from Module 01's PermissionSeeder, and it grants with
 *    syncWithoutDetaching() rather than sync(). Module 01 uses sync(), which REPLACES a
 *    role's permission set, so permissions owned here would have to be repeated in
 *    Module 01's list to survive a re-seed. Keeping them apart lets each module own its own
 *    permissions and neither can silently revoke the other's.
 *
 * 2. Permissions are created with updateOrCreate() on their name, so re-seeding an
 *    installation that is already running neither fails on the unique name index nor
 *    duplicates rows.
 *
 * ORDERING IS LOAD-BEARING: this must run after PermissionSeeder in both DatabaseSeeder and
 * tests\TestCase. Module 01's sync() would otherwise replace the roles' permission sets
 * afterwards and revoke every grant made here, which is a silent failure - the rows and the
 * pivot entries would all look correct in the database and every student route would answer
 * 403.
 *
 * THE PERMISSION NAMES ARE PLURAL, students.*
 *
 * That follows the majority convention in this project - school.view, academic_sessions.*,
 * class_levels.*, classes.*, sections.*, terms.* - and it is the form Module 01 already
 * anticipated in Permission's own docblock and in AuthServiceProvider, both of which use
 * "students.view" as their worked example, and which tests/Pest.php already registers as a
 * throwaway gated route.
 *
 * Module 03's staff.* is the outlier: singular where every other module is plural. It is left
 * as it is rather than renamed, because renaming a seeded permission in a completed module
 * is a data migration and a breaking change to a live API for the sake of a consistency that
 * is already 90% of the way there. Worth fixing in a dedicated pass if the project ever
 * wants it.
 */
class StudentPermissionSeeder extends Seeder
{
    /**
     * @var array<string, array{label: string, description: string}>
     */
    protected array $permissions = [
        'students.view' => [
            'label' => 'View students',
            'description' => 'List and read pupil records.',
        ],
        'students.create' => [
            'label' => 'Create students',
            'description' => 'Create a pupil record. Does not create a login account.',
        ],
        'students.update' => [
            'label' => 'Update students',
            'description' => 'Amend pupil identity details and lifecycle status.',
        ],
    ];

    /**
     * There are deliberately only three, and no students.delete.
     *
     * No delete endpoint is registered in Module 04, so a permission for it would be a grant
     * with no meaning - the same reasoning that left staff.delete out. A pupil is not removed
     * from the school; they leave the roll by status, and the record stays.
     *
     * There is also no students.status, separating lifecycle from profile editing the way
     * Module 03 separated staff.activate and staff.deactivate. The brief asked for only the
     * permissions the module actually needs, and three is what four endpoints require. It is
     * a defensible narrowing rather than an oversight, and adding a fourth later is additive:
     * split the transition out of the amend, add the permission, move the grant.
     *
     * @var array<string, list<string>>
     */
    protected array $rolePermissions = [
        Role::SUPER_ADMIN->value => [
            'students.view', 'students.create', 'students.update',
        ],
        // An administrator runs the school day to day, and the pupil roll is part of that.
        Role::ADMIN->value => [
            'students.view', 'students.create', 'students.update',
        ],
        // A registrar's stated job, in this project's own words, is "Handles admissions,
        // enrollment and student records" - see RoleSeeder. The roll is the core of it.
        //
        // They get the lifecycle through students.update rather than a permission of their
        // own. Withdrawing a pupil is a record-keeping fact about the roll, not a
        // supervisory decision like ending an employment: a registrar records that a child
        // moved away, and the terminal states are enforced in the service rather than by
        // withholding a permission.
        Role::REGISTRAR->value => [
            'students.view', 'students.create', 'students.update',
        ],
        // Staff get nothing. A teacher needs to know which class a pupil is in, and that is
        // a question about an enrollment - a future module's answer - not a licence to read
        // and rewrite the whole roll.
        Role::STAFF->value => [],
        // Students get nothing over the roll. A pupil's own record is reached through their
        // own account, and the whole roll is not theirs to browse. profile.* is Module 01's
        // self-service pair, and it stays the only thing a pupil holds.
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

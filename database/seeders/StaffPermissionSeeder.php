<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Permission;
use App\Models\Role as RoleModel;
use Illuminate\Database\Seeder;

/**
 * Module 03 seeds the permissions for staff management and grants them to the roles
 * that already exist.
 *
 * Same two deliberate choices as Module 02's AcademicPermissionSeeder, for the same
 * reasons:
 *
 * 1. This is a SEPARATE seeder from Module 01's PermissionSeeder, and it grants with
 *    syncWithoutDetaching() rather than sync(). Module 01's seeder uses sync(), which
 *    REPLACES a role's permission set. If these permissions lived there, every re-seed
 *    would have to repeat Module 01's whole list to survive. Keeping them apart lets each
 *    module own its own permissions and neither can silently revoke the other's.
 *
 * 2. Permissions are created with updateOrCreate() on their name, so re-seeding an
 *    installation that is already running neither fails on the unique name index nor
 *    duplicates rows.
 *
 * ORDERING IS LOAD-BEARING: this must run after PermissionSeeder in both DatabaseSeeder
 * and tests\TestCase. Module 01's sync() would otherwise replace the roles' permission
 * sets afterwards and revoke every grant made here, which is a silent failure - the rows
 * and the pivot entries would all look correct in the database and every staff route would
 * answer 403.
 */
class StaffPermissionSeeder extends Seeder
{
    /**
     * @var array<string, array{label: string, description: string}>
     */
    protected array $permissions = [
        'staff.view' => [
            'label' => 'View staff',
            'description' => 'List and read staff records.',
        ],
        'staff.create' => [
            'label' => 'Create staff',
            'description' => 'Create a staff record together with its login account.',
        ],
        'staff.update' => [
            'label' => 'Update staff',
            'description' => 'Amend staff type, staff number, employment details and status.',
        ],
        'staff.activate' => [
            'label' => 'Activate staff',
            'description' => 'Return a staff member to active employment.',
        ],
        'staff.deactivate' => [
            'label' => 'Deactivate staff',
            'description' => 'Record that a staff member is no longer actively employed.',
        ],
    ];

    /**
     * There is deliberately no staff.delete. Module 03 registers no DELETE endpoint, and a
     * permission for an operation that cannot be performed is a grant with no meaning.
     *
     * @var array<string, list<string>>
     */
    protected array $rolePermissions = [
        Role::SUPER_ADMIN->value => [
            'staff.view', 'staff.create', 'staff.update', 'staff.activate', 'staff.deactivate',
        ],
        // An administrator runs the school day to day, and hiring and departure are part of
        // running it. They also hold the keys to employment status, which is why deactivate
        // sits with them rather than the registrar.
        Role::ADMIN->value => [
            'staff.view', 'staff.create', 'staff.update', 'staff.activate', 'staff.deactivate',
        ],
        // A registrar builds the staff establishment as part of admitting and placing
        // people, so they read, create and amend.
        //
        // They deliberately do NOT get staff.activate or staff.deactivate. Deactivation is
        // NOT a security action - it leaves the login account, its verification and its
        // tokens completely untouched, so it cannot cut anybody off. The real reason to hold
        // the transitions at ADMIN is that ending or resuming an employment is a
        // supervisory decision about a person, not a data edit, and it is the decision most
        // expensive to get wrong and hardest to notice. The brief also asked that a
        // registrar not hold these without justification. One line here if the school
        // decides otherwise.
        Role::REGISTRAR->value => [
            'staff.view', 'staff.create', 'staff.update',
        ],
        // A staff member manages nobody. Their own profile is Module 01's profile.*, and
        // looking themselves up in the staff list is not a need the API has.
        Role::STAFF->value => [],
        // Students get nothing. A student's own record arrives with the student module.
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

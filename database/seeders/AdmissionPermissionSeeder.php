<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Permission;
use App\Models\Role as RoleModel;
use Illuminate\Database\Seeder;

/**
 * Module 05 seeds the permissions for admission management and grants them to the roles that
 * already exist.
 *
 * Same two deliberate choices as every module seeder before it, for the same reasons: a
 * SEPARATE seeder from Module 01's PermissionSeeder, granting with syncWithoutDetaching()
 * rather than sync() so it adds to a role's permission set instead of replacing it; and
 * updateOrCreate() on the permission name so re-seeding is safe.
 *
 * ORDERING IS LOAD-BEARING, exactly as for every seeder above: this must run after
 * PermissionSeeder in both DatabaseSeeder and tests\TestCase, or Module 01's sync() would
 * revoke every grant made here.
 */
class AdmissionPermissionSeeder extends Seeder
{
    /**
     * @var array<string, array{label: string, description: string}>
     */
    protected array $permissions = [
        'admissions.view' => [
            'label' => 'View admissions',
            'description' => 'List and read admission records.',
        ],
        'admissions.create' => [
            'label' => 'Create admissions',
            'description' => 'Record a new admission. Does not create a student.',
        ],
        'admissions.update' => [
            'label' => 'Update admissions',
            'description' => 'Amend a pending admission\'s applicant details, target session and entry level.',
        ],
        'admissions.admit' => [
            'label' => 'Admit applicants',
            'description' => 'Accept a pending admission. Creates and links a student record.',
        ],
        'admissions.reject' => [
            'label' => 'Reject applicants',
            'description' => 'Decline a pending admission.',
        ],
        'admissions.withdraw' => [
            'label' => 'Withdraw admissions',
            'description' => 'Record that an applicant withdrew before a decision was made.',
        ],
    ];

    /**
     * Six permissions, one per real operation. There is no admissions.delete: no destroy()
     * is registered, and a permission for an operation that cannot be performed is a grant
     * with no meaning - the same reasoning that left staff.delete and students.delete out.
     *
     * admit, reject and withdraw are separate from update and from each other, following
     * Module 03's activate/deactivate split: each is a state transition with a side effect
     * beyond the record it names (admit's side effect is a created Student), so each can be
     * granted independently of the ability to edit a pending record.
     *
     * @var array<string, list<string>>
     */
    protected array $rolePermissions = [
        Role::SUPER_ADMIN->value => [
            'admissions.view', 'admissions.create', 'admissions.update',
            'admissions.admit', 'admissions.reject', 'admissions.withdraw',
        ],
        // An administrator runs the school day to day, and deciding who is admitted is part
        // of that.
        Role::ADMIN->value => [
            'admissions.view', 'admissions.create', 'admissions.update',
            'admissions.admit', 'admissions.reject', 'admissions.withdraw',
        ],
        // Unlike Module 03's staff.activate/staff.deactivate - deliberately withheld from
        // REGISTRAR because ending someone's employment is a supervisory decision distinct
        // from a registrar's ordinary duties - admission decisions ARE a registrar's stated
        // duties. RoleSeeder's own words: "Handles admissions, enrollment and student
        // records." A registrar who could record an application but not decide it would be
        // unable to do the job the role exists for, so all six are granted together.
        Role::REGISTRAR->value => [
            'admissions.view', 'admissions.create', 'admissions.update',
            'admissions.admit', 'admissions.reject', 'admissions.withdraw',
        ],
        // A teacher processes nobody's admission. Once a student is created, a class
        // assignment is an enrollment question for a future module, not this one.
        Role::STAFF->value => [],
        // A student holds nothing over the admissions queue. Module 01's profile.* pair
        // stays the only thing a pupil's own account can reach.
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

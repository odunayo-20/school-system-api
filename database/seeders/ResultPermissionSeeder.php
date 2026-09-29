<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Permission;
use App\Models\Role as RoleModel;
use Illuminate\Database\Seeder;

/**
 * Module 12 seeds the permissions for result compilation and grants them to the roles that
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
class ResultPermissionSeeder extends Seeder
{
    /**
     * @var array<string, array{label: string, description: string}>
     */
    protected array $permissions = [
        'results.view' => [
            'label' => 'View results',
            'description' => 'List and read compiled subject results.',
        ],
        'results.compile' => [
            'label' => 'Compile results',
            'description' => 'Compile or recompile a student\'s subject result, singly or for a whole class subject.',
        ],
    ];

    /**
     * Two permissions, no results.create/results.update/results.delete: a result is written
     * only through compile() - a calculation, never a raw create, amend or delete of
     * client-supplied values - so there is no second write operation to gate separately, and
     * no destroy() is registered at all. There is likewise no separate "bulk" permission:
     * compiling a whole class subject's results in one call is the same capability as
     * compiling one student's, performed at the shape a class teacher actually works in.
     *
     * STAFF holds both - a deliberate departure from Module 09's assessments.* and Module 11's
     * grading_scales.* (STAFF holds nothing) and a match for Module 10's scores.* (STAFF holds
     * everything): compiling a result is a teaching-adjacent, per-class-subject act performed
     * BY the teacher responsible for it, the same shape scores.* already has. The permission
     * alone is not the whole authorization answer here, exactly as for scores.* -
     * ResultService additionally scopes every read and write to class subjects the acting
     * STAFF member holds an ACTIVE TeacherAssignment for, for the same academic session the
     * term belongs to.
     *
     * @var array<string, list<string>>
     */
    protected array $rolePermissions = [
        Role::SUPER_ADMIN->value => [
            'results.view', 'results.compile',
        ],
        Role::ADMIN->value => [
            'results.view', 'results.compile',
        ],
        // Full access, not view-only - a deliberate departure from Module 08's
        // teacher_assignments.* and Module 11's grading_scales.* (both narrow REGISTRAR to
        // view-only as staffing/policy decisions outside RoleSeeder's own stated duties).
        // Compiling a result is mechanical execution of an already-configured process
        // (assessments, weights and grading scales all configured by others, in earlier
        // modules) over student records - the same "admissions, enrollment and student
        // records" territory RoleSeeder already names for REGISTRAR, and the identical bucket
        // Module 09's curriculum-structure permissions fall into, not a policy decision like
        // Module 11's grade boundaries.
        Role::REGISTRAR->value => [
            'results.view', 'results.compile',
        ],
        Role::STAFF->value => [
            'results.view', 'results.compile',
        ],
        // A student holds nothing over results in this module. Raw compiled results, ahead of
        // any approval or publication, are working data for teachers and administrators;
        // student-facing access belongs to a future Result Checker module - see the Module 12
        // audit.
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

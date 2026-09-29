<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Permission;
use App\Models\Role as RoleModel;
use Illuminate\Database\Seeder;

/**
 * Module 11 seeds the permissions for grading scale configuration and grants them to the
 * roles that already exist.
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
class GradingPermissionSeeder extends Seeder
{
    /**
     * @var array<string, array{label: string, description: string}>
     */
    protected array $permissions = [
        'grading_scales.view' => [
            'label' => 'View grading scales',
            'description' => 'List and read grading scales, their percentage bands, and preview a grade calculation.',
        ],
        'grading_scales.create' => [
            'label' => 'Create grading scales',
            'description' => 'Configure a new grading scale for a class level, with its percentage bands.',
        ],
        'grading_scales.update' => [
            'label' => 'Update grading scales',
            'description' => 'Amend a grading scale\'s identity, status, or its percentage bands.',
        ],
    ];

    /**
     * Three permissions, no grading_scales.delete: no destroy() is registered - see
     * GradingScaleController - so a permission for an operation that cannot be performed would
     * be a grant with no meaning. There is likewise no separate "calculate" permission: the
     * calculation endpoint is a read-only preview of a scale's own configuration, gated on
     * grading_scales.view rather than a new permission invented for one read-only operation.
     *
     * @var array<string, list<string>>
     */
    protected array $rolePermissions = [
        Role::SUPER_ADMIN->value => [
            'grading_scales.view', 'grading_scales.create', 'grading_scales.update',
        ],
        Role::ADMIN->value => [
            'grading_scales.view', 'grading_scales.create', 'grading_scales.update',
        ],
        // View only, matching Module 08's split for teacher_assignments.* rather than Module
        // 09's split for assessments.*: defining grade boundaries is a school ACADEMIC POLICY
        // decision, further still from RoleSeeder's own stated registrar duties
        // ("admissions, enrollment and student records") than assessment configuration
        // already was. A registrar still needs to READ grading scales - to interpret a roster
        // or answer what a percentage means - so view (and the calculation preview it gates)
        // is granted.
        Role::REGISTRAR->value => [
            'grading_scales.view',
        ],
        // Teaching staff enter raw scores (Module 10) but do not thereby gain any say over
        // what those scores MEAN - the brief's own explicit instruction. Unlike Module 10's
        // scores.*, there is no scoped exception here to carve out: grading configuration is
        // school-wide policy, not a per-class-subject teaching duty, so there is no
        // "assigned teacher" scope that would ever make sense to grant.
        Role::STAFF->value => [],
        // A student holds nothing over grading configuration.
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

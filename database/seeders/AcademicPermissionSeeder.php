<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Permission;
use App\Models\Role as RoleModel;
use Illuminate\Database\Seeder;

/**
 * Module 02 seeds the permissions for the school configuration and academic
 * foundation, and grants them to the roles that already exist.
 *
 * Two deliberate choices:
 *
 * 1. This is a SEPARATE seeder from Module 01's PermissionSeeder, and it grants with
 *    syncWithoutDetaching() rather than sync(). Module 01's seeder uses sync(), which
 *    REPLACES a role's permission set. If Module 02's permissions were added to that
 *    seeder, then re-seeding would be fine, but a role's Module 01 grants would have to be
 *    repeated in the Module 02 list to survive. Keeping the two apart means each module
 *    owns its own permissions and neither can silently revoke the other's, and
 *    syncWithoutDetaching() guarantees re-running either one preserves the other.
 *
 * 2. Permissions are created with updateOrCreate() on their name, so re-seeding an
 *    installation that has already been running does not fail on the unique name index
 *    and does not duplicate rows.
 */
class AcademicPermissionSeeder extends Seeder
{
    /**
     * @var array<string, array{label: string, description: string}>
     */
    protected array $permissions = [
        'school.view' => [
            'label' => 'View school profile',
            'description' => 'Read the school profile and contact details.',
        ],
        'school.update' => [
            'label' => 'Update school profile',
            'description' => 'Edit the school profile and contact details.',
        ],
        'academic_sessions.view' => [
            'label' => 'View academic sessions',
            'description' => 'List and read academic sessions.',
        ],
        'academic_sessions.create' => [
            'label' => 'Create academic sessions',
            'description' => 'Create academic sessions.',
        ],
        'academic_sessions.update' => [
            'label' => 'Update academic sessions',
            'description' => 'Edit academic session names and dates.',
        ],
        'academic_sessions.delete' => [
            'label' => 'Delete academic sessions',
            'description' => 'Delete academic sessions that have never been used.',
        ],
        'academic_sessions.activate' => [
            'label' => 'Activate academic sessions',
            'description' => 'Make an academic session the current one, completing the previous session.',
        ],
        'terms.view' => [
            'label' => 'View terms',
            'description' => 'List and read terms.',
        ],
        'terms.create' => [
            'label' => 'Create terms',
            'description' => 'Add terms to an academic session.',
        ],
        'terms.update' => [
            'label' => 'Update terms',
            'description' => 'Edit term names, numbers and dates.',
        ],
        'terms.delete' => [
            'label' => 'Delete terms',
            'description' => 'Delete terms that are not the current term.',
        ],
        'terms.activate' => [
            'label' => 'Activate terms',
            'description' => 'Make a term the current term of the current session.',
        ],
        'class_levels.view' => [
            'label' => 'View class levels',
            'description' => 'List and read class levels.',
        ],
        'class_levels.create' => [
            'label' => 'Create class levels',
            'description' => 'Create class levels.',
        ],
        'class_levels.update' => [
            'label' => 'Update class levels',
            'description' => 'Edit class level names, codes and order.',
        ],
        'class_levels.delete' => [
            'label' => 'Delete class levels',
            'description' => 'Delete class levels that have no classes.',
        ],
        'classes.view' => [
            'label' => 'View classes',
            'description' => 'List and read classes.',
        ],
        'classes.create' => [
            'label' => 'Create classes',
            'description' => 'Create classes within a class level.',
        ],
        'classes.update' => [
            'label' => 'Update classes',
            'description' => 'Edit class names, codes and order.',
        ],
        'classes.delete' => [
            'label' => 'Delete classes',
            'description' => 'Delete classes that have no sections.',
        ],
        'sections.view' => [
            'label' => 'View sections',
            'description' => 'List and read sections.',
        ],
        'sections.create' => [
            'label' => 'Create sections',
            'description' => 'Create sections within a class.',
        ],
        'sections.update' => [
            'label' => 'Update sections',
            'description' => 'Edit section names, codes and order.',
        ],
        'sections.delete' => [
            'label' => 'Delete sections',
            'description' => 'Delete sections.',
        ],
    ];

    /**
     * @var array<string, list<string>>
     */
    protected array $rolePermissions = [
        Role::SUPER_ADMIN->value => [
            'school.view', 'school.update',
            'academic_sessions.view', 'academic_sessions.create', 'academic_sessions.update', 'academic_sessions.delete', 'academic_sessions.activate',
            'terms.view', 'terms.create', 'terms.update', 'terms.delete', 'terms.activate',
            'class_levels.view', 'class_levels.create', 'class_levels.update', 'class_levels.delete',
            'classes.view', 'classes.create', 'classes.update', 'classes.delete',
            'sections.view', 'sections.create', 'sections.update', 'sections.delete',
        ],
        // An administrator runs the school day to day, including retiring an abandoned
        // session, but must not be able to erase academic history. Deleting a session or
        // term that later modules have already referenced is irreversible, so it is kept
        // to the super administrator.
        Role::ADMIN->value => [
            'school.view', 'school.update',
            'academic_sessions.view', 'academic_sessions.create', 'academic_sessions.update', 'academic_sessions.activate',
            'terms.view', 'terms.create', 'terms.update', 'terms.activate',
            'class_levels.view', 'class_levels.create', 'class_levels.update', 'class_levels.delete',
            'classes.view', 'classes.create', 'classes.update', 'classes.delete',
            'sections.view', 'sections.create', 'sections.update', 'sections.delete',
        ],
        // A registrar builds the calendar and the class structure as part of admitting
        // and placing students, so they create and amend terms. Creating sessions is
        // deliberately absent: which year the school is in is an administrative decision.
        Role::REGISTRAR->value => [
            'school.view',
            'academic_sessions.view',
            'terms.view', 'terms.create', 'terms.update',
            'class_levels.view', 'class_levels.create', 'class_levels.update',
            'classes.view', 'classes.create', 'classes.update',
            'sections.view', 'sections.create', 'sections.update',
        ],
        // Staff need to look up the class and section they are working with, but the
        // structure is not theirs to change.
        Role::STAFF->value => [
            'school.view',
            'academic_sessions.view',
            'terms.view',
            'class_levels.view',
            'classes.view',
            'sections.view',
        ],
        // Students get no academic configuration access. A student's own session and
        // term arrive with the student module, scoped to their own enrollment, and are
        // not the school-wide configuration this module exposes.
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

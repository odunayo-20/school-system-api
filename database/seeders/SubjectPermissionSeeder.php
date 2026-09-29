<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Permission;
use App\Models\Role as RoleModel;
use Illuminate\Database\Seeder;

/**
 * Module 07 seeds the permissions for the subject catalogue and class-subject offerings, and
 * grants them to the roles that already exist.
 *
 * One seeder for both, mirroring AcademicPermissionSeeder's own choice to seed
 * class_levels.*, classes.* and sections.* together: they are one module's two closely
 * related entities, not two unrelated catalogues.
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
class SubjectPermissionSeeder extends Seeder
{
    /**
     * @var array<string, array{label: string, description: string}>
     */
    protected array $permissions = [
        'subjects.view' => [
            'label' => 'View subjects',
            'description' => 'List and read the subject catalogue.',
        ],
        'subjects.create' => [
            'label' => 'Create subjects',
            'description' => 'Add a subject to the catalogue.',
        ],
        'subjects.update' => [
            'label' => 'Update subjects',
            'description' => 'Amend a subject\'s name, code, order and status.',
        ],
        'subjects.delete' => [
            'label' => 'Delete subjects',
            'description' => 'Remove a subject that no class currently offers.',
        ],
        'class_subjects.view' => [
            'label' => 'View class subjects',
            'description' => 'List and read which classes offer which subjects.',
        ],
        'class_subjects.create' => [
            'label' => 'Create class subjects',
            'description' => 'Offer a subject to a class.',
        ],
        'class_subjects.update' => [
            'label' => 'Update class subjects',
            'description' => 'Change whether a class subject is currently offered.',
        ],
    ];

    /**
     * subjects.* has a delete permission, matching class_levels.*, classes.* and
     * sections.* - Subject is a catalogue entry, the same family as those three, not a
     * person or a one-shot decision like staff/students/admissions/enrollments, none of
     * which has one.
     *
     * class_subjects.* deliberately has NO delete permission: no destroy() is registered -
     * see ClassSubjectController - so a permission for an operation that cannot be performed
     * would be a grant with no meaning.
     *
     * @var array<string, list<string>>
     */
    protected array $rolePermissions = [
        Role::SUPER_ADMIN->value => [
            'subjects.view', 'subjects.create', 'subjects.update', 'subjects.delete',
            'class_subjects.view', 'class_subjects.create', 'class_subjects.update',
        ],
        // An administrator runs the school day to day, and the curriculum is part of that -
        // the identical reasoning AcademicPermissionSeeder gives ADMIN full rights over class
        // levels, classes and sections.
        Role::ADMIN->value => [
            'subjects.view', 'subjects.create', 'subjects.update', 'subjects.delete',
            'class_subjects.view', 'class_subjects.create', 'class_subjects.update',
        ],
        // A registrar builds the class structure as part of admitting and placing students -
        // AcademicPermissionSeeder's own words for why REGISTRAR holds class_levels.create/
        // update, classes.create/update and sections.create/update but none of their delete
        // permissions. The subject catalogue and its offerings are the same kind of
        // structural work, so the same split applies here.
        Role::REGISTRAR->value => [
            'subjects.view', 'subjects.create', 'subjects.update',
            'class_subjects.view', 'class_subjects.create', 'class_subjects.update',
        ],
        // A teacher does not manage the catalogue merely by teaching from it. Knowing which
        // subjects THEY are assigned to teach is a future Teacher/Class/Subject Assignment
        // module's question, not a licence to create or amend subjects and offerings for the
        // whole school - the identical restraint Module 05 and Module 06 both applied to
        // STAFF over admissions and enrollments.
        Role::STAFF->value => [],
        // A student holds nothing over the catalogue. Module 01's profile.* pair stays the
        // only self-service surface.
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

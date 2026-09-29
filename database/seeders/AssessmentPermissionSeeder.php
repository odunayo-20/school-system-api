<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Permission;
use App\Models\Role as RoleModel;
use Illuminate\Database\Seeder;

/**
 * Module 09 seeds the permissions for the assessment type catalogue and the assessments
 * configured against it, and grants them to the roles that already exist.
 *
 * One seeder for both, mirroring SubjectPermissionSeeder's own choice to seed subjects.* and
 * class_subjects.* together: they are one module's two closely related entities, not two
 * unrelated catalogues.
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
class AssessmentPermissionSeeder extends Seeder
{
    /**
     * @var array<string, array{label: string, description: string}>
     */
    protected array $permissions = [
        'assessment_types.view' => [
            'label' => 'View assessment types',
            'description' => 'List and read the assessment category catalogue.',
        ],
        'assessment_types.create' => [
            'label' => 'Create assessment types',
            'description' => 'Add a category to the assessment type catalogue.',
        ],
        'assessment_types.update' => [
            'label' => 'Update assessment types',
            'description' => 'Amend a category\'s name, code, order and status.',
        ],
        'assessment_types.delete' => [
            'label' => 'Delete assessment types',
            'description' => 'Remove a category that no configured assessment currently uses.',
        ],
        'assessments.view' => [
            'label' => 'View assessments',
            'description' => 'List and read which assessments are configured for which class subjects and terms.',
        ],
        'assessments.create' => [
            'label' => 'Create assessments',
            'description' => 'Configure a new assessment against a class subject and term.',
        ],
        'assessments.update' => [
            'label' => 'Update assessments',
            'description' => 'Amend an assessment\'s name, score ceiling, weight, order or status.',
        ],
    ];

    /**
     * assessment_types.* has a delete permission, matching subjects.*, class_levels.*,
     * classes.* and sections.* - an assessment type is a catalogue entry, the same family as
     * those, not a person or a one-shot decision.
     *
     * assessments.* deliberately has NO delete permission: no destroy() is registered - see
     * AssessmentController - so a permission for an operation that cannot be performed would
     * be a grant with no meaning.
     *
     * @var array<string, list<string>>
     */
    protected array $rolePermissions = [
        Role::SUPER_ADMIN->value => [
            'assessment_types.view', 'assessment_types.create', 'assessment_types.update', 'assessment_types.delete',
            'assessments.view', 'assessments.create', 'assessments.update',
        ],
        // An administrator runs the school day to day, and the assessment structure is part
        // of that - the identical reasoning SubjectPermissionSeeder gives ADMIN full rights
        // over the subject catalogue and its offerings.
        Role::ADMIN->value => [
            'assessment_types.view', 'assessment_types.create', 'assessment_types.update', 'assessment_types.delete',
            'assessments.view', 'assessments.create', 'assessments.update',
        ],
        // A registrar builds curriculum structure as part of admitting and placing students -
        // SubjectPermissionSeeder's own words for why REGISTRAR holds subjects.create/update
        // and class_subjects.create/update but none of their delete permissions. Assessment
        // configuration is the same kind of structural work (RoleSeeder names "admissions,
        // enrollment and student records" among a registrar's duties, and the assessment
        // structure exists to support the same records), so the same split applies here -
        // unlike Module 08's teacher_assignments.*, which is a staffing decision REGISTRAR is
        // deliberately narrowed to view-only for.
        Role::REGISTRAR->value => [
            'assessment_types.view', 'assessment_types.create', 'assessment_types.update',
            'assessments.view', 'assessments.create', 'assessments.update',
        ],
        // A teacher does not configure the assessment structure merely by using it. Knowing
        // which assessments exist for the class subjects THEY are assigned to teach is a
        // future Assessment Scores module's question (where scoped, ownership-based
        // authorization will need to exist for the first time), not a licence to configure
        // assessments for the whole school now - the identical restraint Module 07 and
        // Module 08 both applied to STAFF over the subject catalogue and teacher assignments.
        Role::STAFF->value => [],
        // A student holds nothing over assessment configuration. Module 01's profile.* pair
        // stays the only self-service surface.
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

<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Permission;
use App\Models\Role as RoleModel;
use Illuminate\Database\Seeder;

/**
 * Module 10 seeds the permissions for score entry and grants them to the roles that already
 * exist.
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
class ScorePermissionSeeder extends Seeder
{
    /**
     * @var array<string, array{label: string, description: string}>
     */
    protected array $permissions = [
        'scores.view' => [
            'label' => 'View scores',
            'description' => 'List and read student scores against configured assessments.',
        ],
        'scores.create' => [
            'label' => 'Create scores',
            'description' => 'Record a student\'s mark against an assessment, singly or in a batch.',
        ],
        'scores.update' => [
            'label' => 'Update scores',
            'description' => 'Amend a recorded mark or its remarks.',
        ],
    ];

    /**
     * Three permissions, no scores.delete: no destroy() is registered - see ScoreController -
     * so a permission for an operation that cannot be performed would be a grant with no
     * meaning. There is likewise no separate "bulk" permission: POST /scores/bulk is gated on
     * the same scores.create a single POST /scores is, since bulk entry is the same operation
     * performed for many students at once, not a distinct capability.
     *
     * STAFF holds all three - a deliberate departure from Module 08's teacher_assignments.*,
     * where STAFF holds nothing. See the Module 10 audit: entering a score is a teaching duty
     * performed BY staff, not an administrative decision ABOUT staff, so the role itself must
     * hold the permission for any teacher to reach the endpoint at all. The permission alone is
     * not the whole authorization answer here, unlike every earlier module - ScoreService
     * additionally scopes every read and write to class subjects the acting STAFF member holds
     * an ACTIVE TeacherAssignment for, which is what actually stops a non-teaching staff member
     * or an unrelated teacher from doing anything with the grant. See ScoreService's own
     * docblock.
     *
     * @var array<string, list<string>>
     */
    protected array $rolePermissions = [
        Role::SUPER_ADMIN->value => [
            'scores.view', 'scores.create', 'scores.update',
        ],
        Role::ADMIN->value => [
            'scores.view', 'scores.create', 'scores.update',
        ],
        // View only, matching Module 08's split for teacher_assignments.* rather than Module
        // 09's split for assessments.*: entering or correcting a score is a teaching act, the
        // same staffing/teaching-domain territory Module 08 already withholds create/update
        // access to from REGISTRAR. A registrar still needs to READ scores - to compile a
        // report or resolve a dispute - so view is granted.
        Role::REGISTRAR->value => [
            'scores.view',
        ],
        Role::STAFF->value => [
            'scores.view', 'scores.create', 'scores.update',
        ],
        // A student holds nothing over scores in this module. Raw, pre-grading marks are
        // working data for teachers; exposing them to the student they belong to (without
        // grading, moderation, or the framing a report card gives) belongs to a future Result
        // Checker module, not this one - see the Module 10 audit.
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

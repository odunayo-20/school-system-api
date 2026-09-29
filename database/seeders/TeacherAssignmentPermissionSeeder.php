<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Permission;
use App\Models\Role as RoleModel;
use Illuminate\Database\Seeder;

/**
 * Module 08 seeds the permissions for teacher assignment and grants them to the roles that
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
class TeacherAssignmentPermissionSeeder extends Seeder
{
    /**
     * @var array<string, array{label: string, description: string}>
     */
    protected array $permissions = [
        'teacher_assignments.view' => [
            'label' => 'View teacher assignments',
            'description' => 'List and read which teaching staff member is responsible for which class subject.',
        ],
        'teacher_assignments.create' => [
            'label' => 'Create teacher assignments',
            'description' => 'Assign a teacher to a class subject for an academic session.',
        ],
        'teacher_assignments.update' => [
            'label' => 'Update teacher assignments',
            'description' => 'Amend an active teaching assignment\'s notes.',
        ],
        'teacher_assignments.end' => [
            'label' => 'End teacher assignments',
            'description' => 'Record that a teacher stopped teaching a class subject this session.',
        ],
        'teacher_assignments.cancel' => [
            'label' => 'Cancel teacher assignments',
            'description' => 'Void a teaching assignment that should not have been created.',
        ],
    ];

    /**
     * Five permissions, one per real operation, the identical shape Module 06 uses for
     * enrollments.*. There is no teacher_assignments.delete: no destroy() is registered, and
     * a permission for an operation that cannot be performed is a grant with no meaning.
     *
     * end and cancel are separated from update and from each other, following Module 06's
     * withdraw/cancel split: each is a state transition with a permanent effect, so each can
     * be granted independently of the ability to make an ordinary amend.
     *
     * @var array<string, list<string>>
     */
    protected array $rolePermissions = [
        Role::SUPER_ADMIN->value => [
            'teacher_assignments.view', 'teacher_assignments.create', 'teacher_assignments.update',
            'teacher_assignments.end', 'teacher_assignments.cancel',
        ],
        // An administrator runs the school day to day, and deciding who teaches what is a
        // staffing decision that sits with them - the same reasoning that gives ADMIN
        // staff.activate/staff.deactivate while REGISTRAR holds neither.
        Role::ADMIN->value => [
            'teacher_assignments.view', 'teacher_assignments.create', 'teacher_assignments.update',
            'teacher_assignments.end', 'teacher_assignments.cancel',
        ],
        // Deliberately VIEW ONLY, a departure from Module 05/06/07's own pattern of granting
        // REGISTRAR the full CRUD their permission set holds. RoleSeeder's own description of
        // the role is "Handles admissions, enrollment and student records" - teacher
        // assignment is not named among a registrar's stated duties the way admissions and
        // enrollment explicitly are. Deciding who teaches what is closer to the supervisory,
        // staffing-level decision Module 03 already withheld from REGISTRAR
        // (staff.activate/staff.deactivate) than to admitting or placing a student. A
        // registrar still needs to READ assignments - to answer "who teaches this class" when
        // building a timetable or a report - so view is granted; creating, amending, ending
        // or cancelling one is not.
        Role::REGISTRAR->value => [
            'teacher_assignments.view',
        ],
        // A teacher does not assign themselves or a colleague. Knowing their OWN teaching
        // load is a future self-service question (a "my assignments" endpoint, if ever
        // built), not a licence to manage the whole school's assignments - the identical
        // restraint every prior module applies to STAFF over administrative data.
        Role::STAFF->value => [],
        // A student holds nothing over assignments. Module 01's profile.* pair stays the
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

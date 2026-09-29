<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Permission;
use App\Models\Role as RoleModel;
use Illuminate\Database\Seeder;

/**
 * Module 17 seeds the permissions for attendance and grants them to the roles that already
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
class AttendancePermissionSeeder extends Seeder
{
    /**
     * @var array<string, array{label: string, description: string}>
     */
    protected array $permissions = [
        'attendance.view' => [
            'label' => 'View attendance',
            'description' => 'List and read attendance marks, and view attendance summaries.',
        ],
        'attendance.record' => [
            'label' => 'Record attendance',
            'description' => 'Record a student\'s attendance mark for a date, singly or for a whole class register.',
        ],
        'attendance.update' => [
            'label' => 'Update attendance',
            'description' => 'Amend a recorded attendance mark\'s status or remarks.',
        ],
    ];

    /**
     * Three permissions, no attendance.delete: no destroy() is registered - see
     * AttendanceController - so a permission for an operation that cannot be performed would
     * be a grant with no meaning. There is likewise no separate "bulk" permission: POST
     * /attendance/bulk is gated on the same attendance.record a single POST /attendance is,
     * matching Module 10's identical decision for scores.create/POST /scores/bulk.
     *
     * STAFF holds all three - the identical departure from Module 08's teacher_assignments.*
     * Module 10 already made for scores.*: taking attendance is a teaching duty performed BY
     * staff, not an administrative decision ABOUT staff. The permission alone is not the whole
     * authorization answer, exactly as for scores.* - AttendanceService additionally scopes
     * every read and write to classes the acting STAFF member holds an ACTIVE
     * TeacherAssignment INTO (any subject, that class, that session), which is what actually
     * stops a non-teaching staff member or a teacher of an unrelated class from doing anything
     * with the grant. See AttendanceService's own docblock.
     *
     * @var array<string, list<string>>
     */
    protected array $rolePermissions = [
        Role::SUPER_ADMIN->value => [
            'attendance.view', 'attendance.record', 'attendance.update',
        ],
        Role::ADMIN->value => [
            'attendance.view', 'attendance.record', 'attendance.update',
        ],
        // View only, matching Module 10's identical split for scores.*: taking or correcting
        // attendance is a teaching act, not registrar territory, but a registrar still needs
        // to READ attendance to resolve a dispute or compile a report.
        Role::REGISTRAR->value => [
            'attendance.view',
        ],
        Role::STAFF->value => [
            'attendance.view', 'attendance.record', 'attendance.update',
        ],
        // A student holds nothing over attendance in this module - nothing in this module's
        // brief asks for student self-access, and no established precedent in this project
        // grants one without an explicit requirement (Module 15's promotions.* takes the
        // identical posture).
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

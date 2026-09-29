<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Permission;
use App\Models\Role as RoleModel;
use Illuminate\Database\Seeder;

/**
 * Module 14 seeds the single permission behind the Report Card module and grants it to the
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
class ReportCardPermissionSeeder extends Seeder
{
    /**
     * @var array<string, array{label: string, description: string}>
     */
    protected array $permissions = [
        'report_cards.view' => [
            'label' => 'View report cards',
            'description' => 'Read a student\'s finalized subject results as a structured report card, and their report-card history.',
        ],
    ];

    /**
     * One permission, no .create/.update/.delete: a report card is a read-only presentation of
     * Result rows Module 12 and 13 already own - there is nothing this module writes, so there
     * is no second operation to gate.
     *
     * STUDENT holds this permission - the first time any role in this project has been granted
     * a business-domain permission over ACADEMIC data about themselves beyond Module 01's bare
     * profile.* pair. ReportCardService further scopes every STUDENT caller to their OWN
     * enrollment/student id, the identical "coarse permission, fine-grained service scope"
     * layering STAFF already gets over results.*. This is deliberately narrower than the
     * "future Result Checker module" every prior module's docs deferred student access to:
     * that module (unauthenticated, PIN/reference-code based) is a different mechanism for a
     * different audience, where this is the authenticated student's own account reading their
     * own already-published record - see the Module 14 audit §7.
     *
     * STAFF holds NOTHING here, a deliberate departure from results.* and scores.* (STAFF
     * holds both, scoped per class subject). A report card spans EVERY subject in a term, but this
     * project's only teacher-scope primitive (TeacherAssignment) is scoped to one class
     * subject at a time - there is no "form teacher"/"class teacher" concept to safely widen
     * that scope into a whole-class view, so granting STAFF access here would either leak
     * subjects a teacher was never assigned to teach, or require inventing a scope this
     * project's architecture does not support. See the Module 14 audit §7 for the full
     * reasoning, and ReportCardService::assertEnrollmentViewable() for where this is enforced
     * a second time at the service layer, not only by withholding the permission.
     *
     * REGISTRAR holds full, unrestricted access - the same "admissions, enrollment and student
     * records" territory RoleSeeder already names for the role, and the identical grant
     * Module 12 already gives results.view.
     *
     * @var array<string, list<string>>
     */
    protected array $rolePermissions = [
        Role::SUPER_ADMIN->value => ['report_cards.view'],
        Role::ADMIN->value => ['report_cards.view'],
        Role::REGISTRAR->value => ['report_cards.view'],
        Role::STAFF->value => [],
        Role::STUDENT->value => ['report_cards.view'],
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

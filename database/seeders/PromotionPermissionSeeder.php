<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Permission;
use App\Models\Role as RoleModel;
use Illuminate\Database\Seeder;

/**
 * Module 15 seeds the permissions for student promotion and grants them to the roles that
 * already exist.
 *
 * Same conventions as every module seeder before it: a SEPARATE seeder from Module 01's
 * PermissionSeeder, granting with syncWithoutDetaching() rather than sync() so it adds to a
 * role's permission set instead of replacing it, and updateOrCreate() on the permission name
 * so re-seeding is safe.
 *
 * Named promotions.* (plural), not promotion.* - matching this project's own universal
 * convention of naming a permission after its resource/table (results.*, enrollments.*,
 * report_cards.*), not the singular phrasing an illustrative brief happened to use.
 *
 * ORDERING IS LOAD-BEARING, exactly as for every seeder above: this must run after
 * PermissionSeeder in both DatabaseSeeder and tests\TestCase, or Module 01's sync() would
 * revoke every grant made here.
 */
class PromotionPermissionSeeder extends Seeder
{
    /**
     * @var array<string, array{label: string, description: string}>
     */
    protected array $permissions = [
        'promotions.view' => [
            'label' => 'View promotions',
            'description' => 'List and read promotion decisions.',
        ],
        'promotions.create' => [
            'label' => 'Create promotions',
            'description' => 'Record a promotion decision - promoted, retained, graduated, or not eligible.',
        ],
    ];

    /**
     * Two permissions, no promotions.update/promotions.delete: a promotion decision is a
     * one-shot historical fact, exactly like an Admission's decision - there is no amend path
     * and no destroy() is registered, so there is nothing for a second permission to gate.
     * There is likewise no promotions.approve: nothing in this project's existing
     * requirements describes a submit-then-approve workflow for a promotion decision the way
     * Module 13 built one for Result - the acting user's own promotions.create grant IS the
     * approval, matching every other one-shot decision in this project (Admission's admit/
     * reject/withdraw, TeacherAssignment's end/cancel).
     *
     * SUPER_ADMIN, ADMIN and REGISTRAR hold both, the identical grant Module 06 already gives
     * enrollments.* - promoting a student creates exactly the kind of academic placement
     * record enrollments.create already governs, so the same roles that may place a student
     * may decide what happens to that placement next.
     *
     * STAFF holds NEITHER, a deliberate departure from scores.* and results.* (STAFF holds
     * both, scoped per class subject or teacher assignment). A promotion decision is a whole-student,
     * whole-placement administrative act, not a per-class-subject teaching act, and this
     * project has no "class teacher"/"form teacher" scope primitive a promotion could safely
     * be limited to - the identical reasoning Module 14 already applied to withhold
     * report_cards.view from STAFF entirely. See the Module 15 audit §7.
     *
     * STUDENT holds nothing. Unlike Module 14's report cards, this module's own brief never
     * names a student-facing access pattern for promotion at all - a student's own future
     * placement is an administrative decision made ABOUT them, not a record Module 15 opens
     * for self-service.
     *
     * @var array<string, list<string>>
     */
    protected array $rolePermissions = [
        Role::SUPER_ADMIN->value => ['promotions.view', 'promotions.create'],
        Role::ADMIN->value => ['promotions.view', 'promotions.create'],
        Role::REGISTRAR->value => ['promotions.view', 'promotions.create'],
        Role::STAFF->value => [],
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

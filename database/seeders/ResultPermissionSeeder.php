<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Permission;
use App\Models\Role as RoleModel;
use Illuminate\Database\Seeder;

/**
 * Module 12 seeded results.view/results.compile. Module 13 adds the four permissions behind
 * the approval workflow - results.submit, results.approve, results.publish, results.lock -
 * to this SAME seeder rather than a new ResultWorkflowPermissionSeeder, because they are
 * permissions on the same resource, granted to the same roles' baseline, and a reader asking
 * "what can hold a results.* permission" should find one answer in one place.
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
        'results.submit' => [
            'label' => 'Submit results',
            'description' => 'Submit a compiled result for approval.',
        ],
        'results.approve' => [
            'label' => 'Approve results',
            'description' => 'Approve a submitted result.',
        ],
        'results.publish' => [
            'label' => 'Publish results',
            'description' => 'Publish an approved result.',
        ],
        'results.lock' => [
            'label' => 'Lock results',
            'description' => 'Lock a published result, making it permanently immutable.',
        ],
    ];

    /**
     * Six permissions, no results.create/results.update/results.delete: a result is written
     * only through compile() and the four workflow transitions - never a raw create, amend or
     * delete of client-supplied values - so there is no second write operation to gate
     * separately, and no destroy() is registered at all. There is likewise no separate "bulk"
     * permission for compile: compiling a whole class subject's results in one call is the
     * same capability as compiling one student's, performed at the shape a class teacher
     * actually works in.
     *
     * submit/approve/publish/lock are each their OWN permission, one per real operation - the
     * identical shape Module 06's enrollments.* and Module 08's teacher_assignments.* already
     * use for their own withdraw/end/cancel transitions, so each can be granted independently
     * of the others and of results.compile.
     *
     * STAFF holds view/compile/submit - a deliberate departure from Module 09's assessments.*
     * and Module 11's grading_scales.* (STAFF holds nothing) and a match for Module 10's
     * scores.* (STAFF holds everything): compiling and submitting a result are teaching-adjacent,
     * per-class-subject acts performed BY the teacher responsible for it, the same shape
     * scores.* already has. The permission alone is not the whole authorization answer here,
     * exactly as for scores.* - ResultService additionally scopes every read, compile and
     * submit to class subjects the acting STAFF member holds an ACTIVE TeacherAssignment for,
     * for the same academic session the term belongs to.
     *
     * STAFF holds NEITHER approve, publish NOR lock. This is the load-bearing decision behind
     * this module's separation of duties: a teacher who compiles and submits a result can
     * never be the one who approves, publishes or locks it, because the permission to do so
     * does not exist on their role at all - enforced structurally by RoleSeeder's own grants,
     * not by a runtime "are you the same user who submitted this" check. See the Module 13
     * audit §7 for why a same-user check was considered and rejected as unnecessary beyond
     * this role-level guarantee, which is also why REGISTRAR does not hold them either.
     *
     * @var array<string, list<string>>
     */
    protected array $rolePermissions = [
        Role::SUPER_ADMIN->value => [
            'results.view', 'results.compile', 'results.submit',
            'results.approve', 'results.publish', 'results.lock',
        ],
        Role::ADMIN->value => [
            'results.view', 'results.compile', 'results.submit',
            'results.approve', 'results.publish', 'results.lock',
        ],
        // Full view/compile/submit access, not view-only - a deliberate departure from Module
        // 08's teacher_assignments.* and Module 11's grading_scales.* (both narrow REGISTRAR
        // to view-only as staffing/policy decisions outside RoleSeeder's own stated duties).
        // Compiling and submitting a result is mechanical execution of an already-configured
        // process (assessments, weights and grading scales all configured by others, in
        // earlier modules) over student records - the same "admissions, enrollment and student
        // records" territory RoleSeeder already names for REGISTRAR, and the identical bucket
        // Module 09's curriculum-structure permissions fall into, not a policy decision like
        // Module 11's grade boundaries.
        //
        // NO approve/publish/lock. Deciding a result is final enough to stand as the school's
        // own record - the academic-oversight decision approval and publication represent - is
        // the same kind of POLICY judgment Module 11 withheld from REGISTRAR over grade
        // boundaries, not a records-keeping task. This is also half of this module's own
        // separation-of-duties design: REGISTRAR can prepare a result for review but never be
        // the one who signs off on it.
        Role::REGISTRAR->value => [
            'results.view', 'results.compile', 'results.submit',
        ],
        Role::STAFF->value => [
            'results.view', 'results.compile', 'results.submit',
        ],
        // A student holds nothing over results in this module. Raw compiled, submitted,
        // approved or even published results are working data for teachers and administrators
        // until a future Result Checker module gives students their own, narrower read access
        // - see the Module 12 and Module 13 audits.
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

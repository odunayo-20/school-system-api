<?php

use App\Enums\Role;
use App\Enums\StaffType;
use App\Enums\UserStatus;
use App\Models\Permission;
use App\Models\TeacherAssignment;

/*
|--------------------------------------------------------------------------
| Result workflow authorization: permission matrix, teacher scope, IDOR and
| separation of duties (Module 13)
|--------------------------------------------------------------------------
*/

/*
| Base authentication
*/

it('refuses every workflow action to an unauthenticated caller', function (): void {
    $result = compiledResult();

    test()->postJson("/api/v1/results/{$result->id}/submit")->assertUnauthorized();
    test()->postJson("/api/v1/results/{$result->id}/approve")->assertUnauthorized();
    test()->postJson("/api/v1/results/{$result->id}/publish")->assertUnauthorized();
    test()->postJson("/api/v1/results/{$result->id}/lock")->assertUnauthorized();
});

it('refuses every workflow action to a suspended account, even with a token minted before the suspension', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);
    $result = compiledResult();

    $admin->forceFill(['status' => UserStatus::SUSPENDED])->save();
    forgetResolvedUser();

    withToken($token)->postJson("/api/v1/results/{$result->id}/submit")->assertForbidden();
});

/*
| Permission matrix
*/

it('grants submit to super admin, admin and registrar', function (): void {
    foreach ([Role::SUPER_ADMIN, Role::ADMIN, Role::REGISTRAR] as $role) {
        $token = loginAs(userWithRole($role));
        $result = compiledResult();

        withToken($token)->postJson("/api/v1/results/{$result->id}/submit")->assertOk();
    }
});

it('grants approve, publish and lock to super admin and admin only', function (): void {
    foreach ([Role::SUPER_ADMIN, Role::ADMIN] as $role) {
        $actor = userWithRole($role);
        $token = loginAs($actor);

        $submitted = submittedResult();
        withToken($token)->postJson("/api/v1/results/{$submitted->id}/approve")->assertOk();

        $approved = approvedResult();
        withToken($token)->postJson("/api/v1/results/{$approved->id}/publish")->assertOk();

        $published = publishedResult();
        withToken($token)->postJson("/api/v1/results/{$published->id}/lock")->assertOk();
    }
});

it('withholds approve, publish and lock from registrar - a deliberate separation from its own submit access', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    withToken($token)->postJson('/api/v1/results/'.submittedResult()->id.'/approve')->assertForbidden();
    withToken($token)->postJson('/api/v1/results/'.approvedResult()->id.'/publish')->assertForbidden();
    withToken($token)->postJson('/api/v1/results/'.publishedResult()->id.'/lock')->assertForbidden();
});

it('withholds approve, publish and lock from staff, even an assigned teaching staff member', function (): void {
    $submitted = submittedResult();
    $teacher = teacherAssignedTo($submitted->classSubject, $submitted->term->academicSession);
    $token = loginAs($teacher->user);

    withToken($token)->postJson("/api/v1/results/{$submitted->id}/approve")->assertForbidden();

    $approved = approvedResult();
    withToken($token)->postJson("/api/v1/results/{$approved->id}/publish")->assertForbidden();

    $published = publishedResult();
    withToken($token)->postJson("/api/v1/results/{$published->id}/lock")->assertForbidden();
});

it('keeps every workflow action away from a pupil account', function (): void {
    $token = loginAs(userWithRole(Role::STUDENT));
    $result = compiledResult();

    withToken($token)->postJson("/api/v1/results/{$result->id}/submit")->assertForbidden();
    withToken($token)->postJson('/api/v1/results/'.submittedResult()->id.'/approve')->assertForbidden();
});

it('requires a specific permission per action rather than one blanket grant', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);

    $admin->role->permissions()->detach(
        Permission::query()->where('name', 'results.approve')->value('id')
    );

    $stillCompiled = compiledResult();
    withToken($token)->postJson("/api/v1/results/{$stillCompiled->id}/submit")->assertOk();

    $submitted = submittedResult();
    withToken($token)->postJson("/api/v1/results/{$submitted->id}/approve")->assertForbidden();
});

it('seeds the four workflow permissions and grants none of them to staff beyond submit', function (): void {
    $staff = App\Models\Role::query()->where('name', Role::STAFF->value)->firstOrFail();
    $held = $staff->permissions()->pluck('name')->all();

    expect($held)->toContain('results.view', 'results.compile', 'results.submit')
        ->not->toContain('results.approve', 'results.publish', 'results.lock');
});

/*
| Teacher scope on submit (the only workflow action STAFF holds)
*/

it('lets an assigned teacher submit their own class subject\'s result', function (): void {
    $result = compiledResult();
    $teacher = teacherAssignedTo($result->classSubject, $result->term->academicSession);
    $token = loginAs($teacher->user);

    withToken($token)->postJson("/api/v1/results/{$result->id}/submit")->assertOk();
});

it('refuses a teacher who is not assigned to the class subject - a Mathematics teacher must not submit English results', function (): void {
    $result = compiledResult();
    $unrelatedTeacher = eligibleTeacher();
    $token = loginAs($unrelatedTeacher->user);

    withToken($token)->postJson("/api/v1/results/{$result->id}/submit")->assertForbidden();

    expect($result->refresh()->status->value)->toBe('COMPILED');
});

it('refuses a teacher whose assignment to this class subject has already ended', function (): void {
    $result = compiledResult();
    $teacher = eligibleTeacher();

    TeacherAssignment::factory()
        ->forTeacher($teacher)
        ->forClassSubject($result->classSubject)
        ->forSession($result->term->academicSession)
        ->ended()
        ->create();

    $token = loginAs($teacher->user);

    withToken($token)->postJson("/api/v1/results/{$result->id}/submit")->assertForbidden();
});

it('refuses a non-teaching staff member even if they somehow hold results.submit', function (): void {
    $result = compiledResult();
    $staffMember = staffMember(StaffType::NON_TEACHING);
    $token = loginAs($staffMember->user);

    withToken($token)->postJson("/api/v1/results/{$result->id}/submit")->assertForbidden();
});

/*
| IDOR: a user must not manipulate another class's or student's result by changing the URL id
*/

it('refuses an unassigned teacher submitting a result by guessing its id (IDOR)', function (): void {
    $resultA = compiledResult();
    $resultB = compiledResult();
    $teacher = teacherAssignedTo($resultA->classSubject, $resultA->term->academicSession);
    $token = loginAs($teacher->user);

    // Teacher is assigned to resultA's class subject, not resultB's - resultB must stay out
    // of reach purely by knowing its id.
    withToken($token)->postJson("/api/v1/results/{$resultB->id}/submit")->assertForbidden();

    expect($resultB->refresh()->status->value)->toBe('COMPILED');
});

it('refuses a registrar approving, publishing or locking a result by id even though they can view and submit it', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $submitted = submittedResult();

    withToken($token)->getJson("/api/v1/results/{$submitted->id}")->assertOk();
    withToken($token)->postJson("/api/v1/results/{$submitted->id}/approve")->assertForbidden();
});

it('answers 404, not 403, for a workflow action against a result id that does not exist', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    withToken($token)->postJson('/api/v1/results/999999/submit')->assertNotFound();
    withToken($token)->postJson('/api/v1/results/999999/approve')->assertNotFound();
});

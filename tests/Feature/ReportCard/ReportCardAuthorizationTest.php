<?php

use App\Enums\ResultStatus;
use App\Enums\Role;
use App\Enums\StaffType;
use App\Enums\UserStatus;
use App\Models\Permission;
use App\Models\Role as RoleModel;

/*
|--------------------------------------------------------------------------
| Report card authorization: permission matrix, self-access and IDOR
| (Module 14)
|--------------------------------------------------------------------------
*/

/*
| Base authentication
*/

it('refuses report cards to an unauthenticated caller', function (): void {
    [$enrollment, $term] = reportCardContext(1, ResultStatus::PUBLISHED);

    test()->getJson("/api/v1/report-cards/enrollments/{$enrollment->id}/terms/{$term->id}")->assertUnauthorized();
    test()->getJson("/api/v1/report-cards/students/{$enrollment->student_id}")->assertUnauthorized();
});

it('refuses report cards to a suspended account, even with a token minted before the suspension', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);
    [$enrollment, $term] = reportCardContext(1, ResultStatus::PUBLISHED);

    $admin->forceFill(['status' => UserStatus::SUSPENDED])->save();
    forgetResolvedUser();

    withToken($token)->getJson("/api/v1/report-cards/enrollments/{$enrollment->id}/terms/{$term->id}")->assertForbidden();
});

/*
| Permission matrix
*/

it('grants access to super admin, admin and registrar', function (): void {
    foreach ([Role::SUPER_ADMIN, Role::ADMIN, Role::REGISTRAR] as $role) {
        $token = loginAs(userWithRole($role));
        [$enrollment, $term] = reportCardContext(1, ResultStatus::PUBLISHED);

        withToken($token)->getJson("/api/v1/report-cards/enrollments/{$enrollment->id}/terms/{$term->id}")->assertOk();
        withToken($token)->getJson("/api/v1/report-cards/students/{$enrollment->student_id}")->assertOk();
    }
});

it('refuses teaching staff outright, even a teacher assigned to one of the subjects on the card', function (): void {
    [$enrollment, $term] = reportCardContext(1, ResultStatus::PUBLISHED);
    $teacher = teacherAssignedTo(activeClassSubject(['school_class_id' => $enrollment->school_class_id]), $enrollment->academicSession);
    $token = loginAs($teacher->user);

    withToken($token)->getJson("/api/v1/report-cards/enrollments/{$enrollment->id}/terms/{$term->id}")->assertForbidden();
});

it('refuses a non-teaching staff member', function (): void {
    [$enrollment, $term] = reportCardContext(1, ResultStatus::PUBLISHED);
    $staffMember = staffMember(StaffType::NON_TEACHING);
    $token = loginAs($staffMember->user);

    withToken($token)->getJson("/api/v1/report-cards/enrollments/{$enrollment->id}/terms/{$term->id}")->assertForbidden();
});

it('seeds exactly one permission for this module', function (): void {
    $names = Permission::query()->where('name', 'like', 'report_cards.%')->pluck('name')->all();

    expect($names)->toEqualCanonicalizing(['report_cards.view']);
});

it('adds the module permission without revoking the earlier modules', function (): void {
    $admin = RoleModel::query()->where('name', Role::ADMIN->value)->firstOrFail();
    $held = $admin->permissions()->pluck('name')->all();

    expect($held)->toContain('results.view')
        ->toContain('report_cards.view');
});

it('requires the report_cards.view permission rather than reusing results.view', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);

    $admin->role->permissions()->detach(
        Permission::query()->where('name', 'report_cards.view')->value('id')
    );

    [$enrollment, $term] = reportCardContext(1, ResultStatus::PUBLISHED);

    // Still holds results.view (a different permission) but not report_cards.view.
    withToken($token)->getJson('/api/v1/results')->assertOk();
    withToken($token)->getJson("/api/v1/report-cards/enrollments/{$enrollment->id}/terms/{$term->id}")->assertForbidden();
});

/*
| Student self-access
*/

it('lets a student view their own report card', function (): void {
    $student = pupilWithAccount();
    [$enrollment, $term] = reportCardContext(1, ResultStatus::PUBLISHED, ['student_id' => $student->id]);
    $token = loginAs($student->user);

    withToken($token)->getJson("/api/v1/report-cards/enrollments/{$enrollment->id}/terms/{$term->id}")->assertOk();
});

it('lets a student list their own report-card history', function (): void {
    $student = pupilWithAccount();
    reportCardContext(1, ResultStatus::PUBLISHED, ['student_id' => $student->id]);
    $token = loginAs($student->user);

    withToken($token)->getJson("/api/v1/report-cards/students/{$student->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('refuses a student viewing another student\'s report card by id (IDOR)', function (): void {
    $studentA = pupilWithAccount();
    [$enrollmentA, $termA] = reportCardContext(1, ResultStatus::PUBLISHED, ['student_id' => $studentA->id]);

    $studentB = pupilWithAccount();
    $tokenB = loginAs($studentB->user);

    withToken($tokenB)->getJson("/api/v1/report-cards/enrollments/{$enrollmentA->id}/terms/{$termA->id}")
        ->assertForbidden();
});

it('refuses a student listing another student\'s report-card history by id (IDOR)', function (): void {
    $studentA = pupilWithAccount();
    reportCardContext(1, ResultStatus::PUBLISHED, ['student_id' => $studentA->id]);

    $studentB = pupilWithAccount();
    $tokenB = loginAs($studentB->user);

    withToken($tokenB)->getJson("/api/v1/report-cards/students/{$studentA->id}")->assertForbidden();
});

it('a student cannot bypass their own scope by simply changing the enrollment id in the URL', function (): void {
    $studentA = pupilWithAccount();
    [$enrollmentA, $termA] = reportCardContext(2, ResultStatus::PUBLISHED, ['student_id' => $studentA->id]);
    $tokenA = loginAs($studentA->user);

    // Legitimately their own.
    withToken($tokenA)->getJson("/api/v1/report-cards/enrollments/{$enrollmentA->id}/terms/{$termA->id}")->assertOk();

    // A neighboring enrollment id, not theirs - never their own data, whether the id belongs
    // to another student (403, ownership check) or to nothing at all (404).
    $response = withToken($tokenA)->getJson('/api/v1/report-cards/enrollments/'.($enrollmentA->id + 1).'/terms/'.$termA->id);

    expect($response->status())->toBeIn([403, 404]);
});

it('keeps report cards away from a student whose own permission grant was revoked', function (): void {
    $student = pupilWithAccount();
    [$enrollment, $term] = reportCardContext(1, ResultStatus::PUBLISHED, ['student_id' => $student->id]);
    $token = loginAs($student->user);

    $student->user->role->permissions()->detach(
        Permission::query()->where('name', 'report_cards.view')->value('id')
    );

    withToken($token)->getJson("/api/v1/report-cards/enrollments/{$enrollment->id}/terms/{$term->id}")->assertForbidden();
});

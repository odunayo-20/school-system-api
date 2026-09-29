<?php

use App\Enums\AttendanceStatus;
use App\Enums\Role;
use App\Enums\StaffType;
use App\Enums\UserStatus;
use App\Models\Attendance;
use App\Models\Permission;
use App\Models\Role as RoleModel;

/*
|--------------------------------------------------------------------------
| Attendance authorization: permission matrix, teacher class-scoping and
| IDOR (Module 17)
|--------------------------------------------------------------------------
*/

/*
| Base authentication
*/

it('refuses attendance endpoints to an unauthenticated caller', function (): void {
    $context = attendanceContext(1);

    test()->postJson('/api/v1/attendance', attendancePayload($context))->assertUnauthorized();
    test()->getJson('/api/v1/attendance')->assertUnauthorized();
});

it('refuses attendance endpoints to a suspended account, even with a token minted before the suspension', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);
    $context = attendanceContext(1);

    $admin->forceFill(['status' => UserStatus::SUSPENDED])->save();
    forgetResolvedUser();

    withToken($token)->postJson('/api/v1/attendance', attendancePayload($context))->assertForbidden();
});

/*
| Permission matrix
*/

it('grants full attendance access to super admin and admin', function (): void {
    foreach ([Role::SUPER_ADMIN, Role::ADMIN] as $role) {
        $token = loginAs(userWithRole($role));
        $context = attendanceContext(1);

        withToken($token)->postJson('/api/v1/attendance', attendancePayload($context))->assertCreated();
        withToken($token)->getJson('/api/v1/attendance')->assertOk();
    }
});

it('grants registrar view only, not record or update', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $context = attendanceContext(1);

    withToken($token)->getJson('/api/v1/attendance')->assertOk();
    withToken($token)->postJson('/api/v1/attendance', attendancePayload($context))->assertForbidden();

    $attendance = recordedAttendance();
    withToken($token)->putJson("/api/v1/attendance/{$attendance->id}", ['status' => AttendanceStatus::ABSENT->value])
        ->assertForbidden();
});

it('refuses a non-teaching staff member', function (): void {
    $context = attendanceContext(1);
    $staff = staffMember(StaffType::NON_TEACHING);
    $token = loginAs($staff->user);

    withToken($token)->postJson('/api/v1/attendance', attendancePayload($context))->assertForbidden();
});

it('refuses a student', function (): void {
    $student = pupilWithAccount();
    $token = loginAs($student->user);

    withToken($token)->getJson('/api/v1/attendance')->assertForbidden();
});

it('seeds exactly three permissions for this module', function (): void {
    $names = Permission::query()->where('name', 'like', 'attendance.%')->pluck('name')->all();

    expect($names)->toEqualCanonicalizing(['attendance.view', 'attendance.record', 'attendance.update']);
});

it('adds the module permissions without revoking the earlier modules', function (): void {
    $admin = RoleModel::query()->where('name', Role::ADMIN->value)->firstOrFail();
    $held = $admin->permissions()->pluck('name')->all();

    expect($held)->toContain('scores.view')
        ->toContain('promotions.view')
        ->toContain('attendance.view')
        ->toContain('attendance.record');
});

it('requires attendance.record separately from attendance.view', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);

    $admin->role->permissions()->detach(
        Permission::query()->where('name', 'attendance.record')->value('id')
    );

    $context = attendanceContext(1);

    withToken($token)->getJson('/api/v1/attendance')->assertOk();
    withToken($token)->postJson('/api/v1/attendance', attendancePayload($context))->assertForbidden();
});

/*
| Teacher class-scoping
*/

it('lets a teacher assigned to the class record and view its attendance', function (): void {
    $context = attendanceContext(1);
    $teacher = teacherAssignedToClass($context);
    $token = loginAs($teacher->user);

    withToken($token)->postJson('/api/v1/attendance', attendancePayload($context))->assertCreated();
    withToken($token)->getJson('/api/v1/attendance')->assertOk()->assertJsonCount(1, 'data');
});

it('refuses a teacher who is not assigned to the class, even one assigned to teach a different class', function (): void {
    $context = attendanceContext(1);
    $elsewhereContext = attendanceContext(1);
    $teacher = teacherAssignedToClass($elsewhereContext);
    $token = loginAs($teacher->user);

    withToken($token)->postJson('/api/v1/attendance', attendancePayload($context))->assertForbidden();
});

it('does not let a teacher reach another class\'s attendance simply by changing school_class_id in the request', function (): void {
    $context = attendanceContext(1);
    $elsewhereContext = attendanceContext(1);
    $teacher = teacherAssignedToClass($elsewhereContext);
    $token = loginAs($teacher->user);

    // The teacher IS assigned to $elsewhereContext's class - but the enrollment named here
    // belongs to a DIFFERENT class ($context's), so this is refused as a context mismatch
    // before authorization is even reached, not granted merely because the class id in the
    // request happens to be one the teacher is assigned to.
    withToken($token)->postJson('/api/v1/attendance', attendancePayload($context, [
        'school_class_id' => $elsewhereContext['schoolClass']->id,
        'section_id' => $elsewhereContext['section']->id,
        'academic_session_id' => $elsewhereContext['session']->id,
    ]))->assertStatus(422);
});

it('scopes a teacher\'s attendance list to only the classes they are assigned to', function (): void {
    $context = attendanceContext(1);
    $elsewhereContext = attendanceContext(1);
    $teacher = teacherAssignedToClass($context);

    recordedAttendance($context);
    recordedAttendance($elsewhereContext);

    $token = loginAs($teacher->user);

    withToken($token)->getJson('/api/v1/attendance')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.enrollment.id', $context['enrollments'][0]->id);
});

it('refuses a teacher viewing a specific attendance record from a class they do not teach (IDOR)', function (): void {
    $context = attendanceContext(1);
    $elsewhereContext = attendanceContext(1);
    $teacher = teacherAssignedToClass($elsewhereContext);
    $attendance = recordedAttendance($context);
    $token = loginAs($teacher->user);

    withToken($token)->getJson("/api/v1/attendance/{$attendance->id}")->assertForbidden();
});

it('refuses a teacher updating attendance for a class they do not teach', function (): void {
    $context = attendanceContext(1);
    $elsewhereContext = attendanceContext(1);
    $teacher = teacherAssignedToClass($elsewhereContext);
    $attendance = recordedAttendance($context);
    $token = loginAs($teacher->user);

    withToken($token)->putJson("/api/v1/attendance/{$attendance->id}", ['status' => AttendanceStatus::ABSENT->value])
        ->assertForbidden();

    expect($attendance->refresh()->status)->not->toBe(AttendanceStatus::ABSENT);
});

it('refuses a teacher an attendance summary for a class they do not teach', function (): void {
    $context = attendanceContext(1);
    $elsewhereContext = attendanceContext(1);
    $teacher = teacherAssignedToClass($elsewhereContext);
    $token = loginAs($teacher->user);

    withToken($token)->getJson("/api/v1/attendance/summary?enrollment_id={$context['enrollments'][0]->id}")
        ->assertForbidden();
});

it('refuses a teacher bulk-recording attendance for a class they do not teach', function (): void {
    $context = attendanceContext(2);
    $elsewhereContext = attendanceContext(1);
    $teacher = teacherAssignedToClass($elsewhereContext);
    $token = loginAs($teacher->user);

    withToken($token)->postJson('/api/v1/attendance/bulk', attendanceBulkPayload($context))->assertForbidden();

    expect(Attendance::query()->count())->toBe(0);
});

it('keeps attendance away from a teacher whose own permission grant was revoked', function (): void {
    $context = attendanceContext(1);
    $teacher = teacherAssignedToClass($context);
    $token = loginAs($teacher->user);

    $teacher->user->role->permissions()->detach(
        Permission::query()->where('name', 'attendance.record')->value('id')
    );

    withToken($token)->postJson('/api/v1/attendance', attendancePayload($context))->assertForbidden();
});

<?php

use App\Enums\AcademicSessionStatus;
use App\Enums\AttendanceStatus;
use App\Enums\Role;
use App\Models\Attendance;
use App\Models\Section;
use App\Models\Term;

/*
|--------------------------------------------------------------------------
| Attendance: recording, bulk recording, updating, retrieval and summary
| (Module 17)
|--------------------------------------------------------------------------
*/

/*
| Recording
*/

it('records a single attendance mark', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $context = attendanceContext(1);

    $response = withToken($token)->postJson('/api/v1/attendance', attendancePayload($context));

    $response->assertCreated()
        ->assertJsonPath('data.status', AttendanceStatus::PRESENT->value)
        ->assertJsonPath('data.enrollment.id', $context['enrollments'][0]->id)
        ->assertJsonPath('data.recorded_by.id', fn (int $id): bool => $id > 0);

    expect(Attendance::query()->count())->toBe(1);
});

it('rejects an invalid attendance status', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $context = attendanceContext(1);

    withToken($token)->postJson('/api/v1/attendance', attendancePayload($context, ['status' => 'ON_LEAVE']))
        ->assertUnprocessable()->assertJsonValidationErrors('status');
});

it('rejects an enrollment that does not exist', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $context = attendanceContext(1);

    withToken($token)->postJson('/api/v1/attendance', attendancePayload($context, ['enrollment_id' => 999999]))
        ->assertUnprocessable()->assertJsonValidationErrors('enrollment_id');
});

it('rejects an enrollment that belongs to a different class than the one named in the request', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $context = attendanceContext(1);
    $otherContext = attendanceContext(1);

    // enrollment belongs to $otherContext's class, but the request claims $context's class.
    withToken($token)->postJson('/api/v1/attendance', attendancePayload($context, [
        'enrollment_id' => $otherContext['enrollments'][0]->id,
    ]))->assertStatus(422);

    expect(Attendance::query()->count())->toBe(0);
});

it('rejects an enrollment that belongs to a different section than the one named in the request', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $context = attendanceContext(1);

    withToken($token)->postJson('/api/v1/attendance', attendancePayload($context, [
        'section_id' => Section::factory()->within($context['schoolClass'], 'B', 'B')->create()->id,
    ]))->assertStatus(422);

    expect(Attendance::query()->count())->toBe(0);
});

it('rejects an enrollment that belongs to a different academic session than the one named in the request', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $context = attendanceContext(1);
    $otherSession = eligibleSession();

    withToken($token)->postJson('/api/v1/attendance', attendancePayload($context, [
        'academic_session_id' => $otherSession->id,
    ]))->assertStatus(422);

    expect(Attendance::query()->count())->toBe(0);
});

it('rejects a section that does not belong to the named class', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $context = attendanceContext(1);
    $otherClass = selectableSchoolClass();
    $foreignSection = Section::factory()->within($otherClass, 'Z', 'Z')->create();

    withToken($token)->postJson('/api/v1/attendance', attendancePayload($context, [
        'section_id' => $foreignSection->id,
    ]))->assertUnprocessable()->assertJsonValidationErrors('section_id');
});

it('rejects a future attendance date', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $context = attendanceContext(1);

    withToken($token)->postJson('/api/v1/attendance', attendancePayload($context, [
        'date' => now()->addDay()->toDateString(),
    ]))->assertUnprocessable()->assertJsonValidationErrors('date');
});

it('rejects an academic session that has already been completed', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $context = attendanceContext(1);
    $context['session']->forceFill(['status' => AcademicSessionStatus::COMPLETED])->save();

    withToken($token)->postJson('/api/v1/attendance', attendancePayload($context))
        ->assertUnprocessable()->assertJsonValidationErrors('academic_session_id');
});

it('rejects a duplicate attendance mark for the same enrollment and date', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $context = attendanceContext(1);

    withToken($token)->postJson('/api/v1/attendance', attendancePayload($context))->assertCreated();

    withToken($token)->postJson('/api/v1/attendance', attendancePayload($context, ['status' => AttendanceStatus::ABSENT->value]))
        ->assertStatus(422);

    expect(Attendance::query()->count())->toBe(1)
        ->and(Attendance::query()->first()->status)->toBe(AttendanceStatus::PRESENT);
});

it('records attendance marks for the same enrollment on different dates', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $context = attendanceContext(1);

    withToken($token)->postJson('/api/v1/attendance', attendancePayload($context, ['date' => '2026-01-05']))->assertCreated();
    withToken($token)->postJson('/api/v1/attendance', attendancePayload($context, ['date' => '2026-01-06']))->assertCreated();

    expect(Attendance::query()->count())->toBe(2);
});

/*
| Bulk attendance
*/

it('records a bulk attendance submission for multiple students', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $context = attendanceContext(3);

    $response = withToken($token)->postJson('/api/v1/attendance/bulk', attendanceBulkPayload($context, [
        1 => ['status' => AttendanceStatus::ABSENT->value],
        2 => ['status' => AttendanceStatus::LATE->value],
    ]));

    $response->assertCreated()->assertJsonCount(3, 'data');

    expect(Attendance::query()->count())->toBe(3)
        ->and(Attendance::query()->where('status', AttendanceStatus::ABSENT->value)->count())->toBe(1)
        ->and(Attendance::query()->where('status', AttendanceStatus::LATE->value)->count())->toBe(1);
});

it('rejects a bulk submission containing an enrollment that does not exist, writing nothing', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $context = attendanceContext(2);
    $payload = attendanceBulkPayload($context);
    $payload['attendances'][] = ['enrollment_id' => 999999, 'status' => AttendanceStatus::PRESENT->value];

    withToken($token)->postJson('/api/v1/attendance/bulk', $payload)->assertUnprocessable();

    expect(Attendance::query()->count())->toBe(0);
});

it('rejects a bulk submission containing an enrollment from a different class, writing nothing', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $context = attendanceContext(2);
    $otherContext = attendanceContext(1);

    $payload = attendanceBulkPayload($context);
    $payload['attendances'][] = ['enrollment_id' => $otherContext['enrollments'][0]->id, 'status' => AttendanceStatus::PRESENT->value];

    $response = withToken($token)->postJson('/api/v1/attendance/bulk', $payload);

    $response->assertStatus(422);
    expect(Attendance::query()->count())->toBe(0);
});

it('is a transaction: a mid-batch failure leaves no partial writes', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $context = attendanceContext(5);
    $otherContext = attendanceContext(1);

    $payload = attendanceBulkPayload($context);
    // The 6th row is invalid (wrong class) - the other five are all otherwise valid.
    $payload['attendances'][] = ['enrollment_id' => $otherContext['enrollments'][0]->id, 'status' => AttendanceStatus::PRESENT->value];

    withToken($token)->postJson('/api/v1/attendance/bulk', $payload)->assertStatus(422);

    expect(Attendance::query()->count())->toBe(0);
});

it('upserts on resubmission - the same batch submitted twice updates in place rather than duplicating', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $context = attendanceContext(2);

    withToken($token)->postJson('/api/v1/attendance/bulk', attendanceBulkPayload($context))->assertCreated();

    $correction = withToken($token)->postJson('/api/v1/attendance/bulk', attendanceBulkPayload($context, [
        0 => ['status' => AttendanceStatus::LATE->value],
        1 => ['status' => AttendanceStatus::EXCUSED->value],
    ]));

    $correction->assertCreated()->assertJsonCount(2, 'data');

    expect(Attendance::query()->count())->toBe(2)
        ->and(Attendance::query()->where('enrollment_id', $context['enrollments'][0]->id)->first()->status)->toBe(AttendanceStatus::LATE)
        ->and(Attendance::query()->where('enrollment_id', $context['enrollments'][1]->id)->first()->status)->toBe(AttendanceStatus::EXCUSED);
});

it('is idempotent - submitting the identical bulk payload twice leaves the same two rows unchanged', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $context = attendanceContext(2);
    $payload = attendanceBulkPayload($context);

    withToken($token)->postJson('/api/v1/attendance/bulk', $payload)->assertCreated();
    withToken($token)->postJson('/api/v1/attendance/bulk', $payload)->assertCreated();

    expect(Attendance::query()->count())->toBe(2);
});

it('rejects more than one hundred rows in a single batch', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $context = attendanceContext(1);
    $payload = attendanceBulkPayload($context);
    $payload['attendances'] = collect(range(1, 101))->map(fn (int $i): array => [
        'enrollment_id' => $context['enrollments'][0]->id,
        'status' => AttendanceStatus::PRESENT->value,
    ])->all();

    withToken($token)->postJson('/api/v1/attendance/bulk', $payload)
        ->assertUnprocessable()->assertJsonValidationErrors('attendances');
});

/*
| Updating
*/

it('updates an attendance mark\'s status and remarks', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $attendance = recordedAttendance();

    $response = withToken($token)->putJson("/api/v1/attendance/{$attendance->id}", [
        'status' => AttendanceStatus::LATE->value,
        'remarks' => 'Arrived 20 minutes late.',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.status', AttendanceStatus::LATE->value)
        ->assertJsonPath('data.remarks', 'Arrived 20 minutes late.');

    expect($attendance->refresh()->status)->toBe(AttendanceStatus::LATE);
});

it('does not let an update reach the enrollment, class, section or date', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $attendance = recordedAttendance();
    $originalEnrollmentId = $attendance->enrollment_id;
    $originalDate = $attendance->date->toDateString();

    withToken($token)->putJson("/api/v1/attendance/{$attendance->id}", [
        'status' => AttendanceStatus::ABSENT->value,
        'enrollment_id' => 999999,
        'date' => '2020-01-01',
    ])->assertOk();

    expect($attendance->refresh()->enrollment_id)->toBe($originalEnrollmentId)
        ->and($attendance->date->toDateString())->toBe($originalDate);
});

it('answers 404 when updating an attendance record that does not exist', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    withToken($token)->putJson('/api/v1/attendance/999999', ['status' => AttendanceStatus::PRESENT->value])
        ->assertNotFound();
});

/*
| Retrieval
*/

it('shows a single attendance record', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $attendance = recordedAttendance();

    withToken($token)->getJson("/api/v1/attendance/{$attendance->id}")
        ->assertOk()->assertJsonPath('data.id', $attendance->id);
});

it('retrieves a student\'s attendance history', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $context = attendanceContext(1);
    $studentId = $context['enrollments'][0]->student_id;

    withToken($token)->postJson('/api/v1/attendance', attendancePayload($context, ['date' => '2026-01-05']))->assertCreated();
    withToken($token)->postJson('/api/v1/attendance', attendancePayload($context, ['date' => '2026-01-06']))->assertCreated();

    withToken($token)->getJson("/api/v1/attendance?student_id={$studentId}")
        ->assertOk()->assertJsonCount(2, 'data');
});

it('retrieves class attendance for a specific date', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $context = attendanceContext(3);

    withToken($token)->postJson('/api/v1/attendance/bulk', attendanceBulkPayload($context, [], ['date' => '2026-02-10']))->assertCreated();
    withToken($token)->postJson('/api/v1/attendance/bulk', attendanceBulkPayload($context, [], ['date' => '2026-02-11']))->assertCreated();

    $response = withToken($token)->getJson(
        "/api/v1/attendance?school_class_id={$context['schoolClass']->id}&section_id={$context['section']->id}&date=2026-02-10"
    );

    $response->assertOk()->assertJsonCount(3, 'data');
});

it('retrieves attendance within a date range', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $context = attendanceContext(1);

    withToken($token)->postJson('/api/v1/attendance', attendancePayload($context, ['date' => '2026-03-01']))->assertCreated();
    withToken($token)->postJson('/api/v1/attendance', attendancePayload($context, ['date' => '2026-03-10']))->assertCreated();
    withToken($token)->postJson('/api/v1/attendance', attendancePayload($context, ['date' => '2026-03-20']))->assertCreated();

    withToken($token)->getJson('/api/v1/attendance?date_from=2026-03-05&date_to=2026-03-15')
        ->assertOk()->assertJsonCount(1, 'data');
});

it('filters attendance by term', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $context = attendanceContext(1);
    $term = Term::factory()->forSession($context['session'], 1)->create([
        'start_date' => '2026-04-01',
        'end_date' => '2026-04-30',
    ]);

    withToken($token)->postJson('/api/v1/attendance', attendancePayload($context, ['date' => '2026-04-15']))->assertCreated();
    withToken($token)->postJson('/api/v1/attendance', attendancePayload($context, ['date' => '2026-05-15']))->assertCreated();

    withToken($token)->getJson("/api/v1/attendance?term_id={$term->id}")
        ->assertOk()->assertJsonCount(1, 'data');
});

it('filters attendance by status', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $context = attendanceContext(2);

    withToken($token)->postJson('/api/v1/attendance/bulk', attendanceBulkPayload($context, [
        1 => ['status' => AttendanceStatus::ABSENT->value],
    ]))->assertCreated();

    withToken($token)->getJson('/api/v1/attendance?status=ABSENT')
        ->assertOk()->assertJsonCount(1, 'data');
});

it('answers 404 for an attendance record that does not exist', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    withToken($token)->getJson('/api/v1/attendance/999999')->assertNotFound();
});

/*
| Summary
*/

it('computes an attendance summary for an enrollment', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $context = attendanceContext(1);

    foreach (['2026-06-01' => 'PRESENT', '2026-06-02' => 'PRESENT', '2026-06-03' => 'ABSENT', '2026-06-04' => 'LATE'] as $date => $status) {
        withToken($token)->postJson('/api/v1/attendance', attendancePayload($context, ['date' => $date, 'status' => $status]))->assertCreated();
    }

    $response = withToken($token)->getJson("/api/v1/attendance/summary?enrollment_id={$context['enrollments'][0]->id}");

    $response->assertOk()
        ->assertJsonPath('data.total_recorded', 4)
        ->assertJsonPath('data.present', 2)
        ->assertJsonPath('data.absent', 1)
        ->assertJsonPath('data.late', 1)
        ->assertJsonPath('data.excused', 0)
        ->assertJsonPath('data.attendance_percentage', 50);
});

it('returns a null percentage, never zero, when nothing has been recorded yet', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $context = attendanceContext(1);

    $response = withToken($token)->getJson("/api/v1/attendance/summary?enrollment_id={$context['enrollments'][0]->id}");

    $response->assertOk()
        ->assertJsonPath('data.total_recorded', 0)
        ->assertJsonPath('data.attendance_percentage', null);
});

it('scopes a summary to a date range within the enrollment\'s own history', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $context = attendanceContext(1);

    withToken($token)->postJson('/api/v1/attendance', attendancePayload($context, ['date' => '2026-07-01', 'status' => 'PRESENT']))->assertCreated();
    withToken($token)->postJson('/api/v1/attendance', attendancePayload($context, ['date' => '2026-07-15', 'status' => 'ABSENT']))->assertCreated();

    withToken($token)->getJson("/api/v1/attendance/summary?enrollment_id={$context['enrollments'][0]->id}&date_from=2026-07-01&date_to=2026-07-10")
        ->assertOk()
        ->assertJsonPath('data.total_recorded', 1)
        ->assertJsonPath('data.present', 1);
});

/*
| Response structure
*/

it('lists attendance through the same envelope as every other list in the API', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    recordedAttendance();

    withToken($token)->getJson('/api/v1/attendance')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [['id', 'enrollment', 'date', 'status', 'remarks', 'recorded_by']],
            'meta' => ['current_page', 'per_page', 'total', 'last_page'],
        ]);
});

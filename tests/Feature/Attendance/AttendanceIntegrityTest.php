<?php

use App\Enums\AttendanceStatus;
use App\Enums\PromotionDecision;
use App\Enums\Role;
use App\Models\AcademicSession;
use App\Models\Attendance;
use App\Models\ClassLevel;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Services\Attendance\AttendanceService;
use App\Services\Promotion\PromotionService;
use Illuminate\Database\QueryException;

/*
|--------------------------------------------------------------------------
| Database integrity: the unique constraint, foreign-key deletion behaviour
| and historical safety across promotion (Module 17)
|--------------------------------------------------------------------------
*/

/*
| Database constraints
*/

it('lets the database catch a duplicate (enrollment_id, date) pair that the service never attempts to insert twice', function (): void {
    $attendance = recordedAttendance();

    expect(fn () => (new Attendance)->forceFill([
        'enrollment_id' => $attendance->enrollment_id,
        'academic_session_id' => $attendance->academic_session_id,
        'school_class_id' => $attendance->school_class_id,
        'section_id' => $attendance->section_id,
        'date' => $attendance->date,
        'status' => AttendanceStatus::ABSENT,
    ])->save())->toThrow(QueryException::class);

    expect(Attendance::query()->where('enrollment_id', $attendance->enrollment_id)->count())->toBe(1);
});

it('refuses to delete an enrollment that an attendance mark still references', function (): void {
    $attendance = recordedAttendance();
    $enrollmentId = $attendance->enrollment_id;

    expect(fn () => Enrollment::query()->findOrFail($enrollmentId)->delete())->toThrow(QueryException::class);

    expect(Enrollment::query()->whereKey($enrollmentId)->exists())->toBeTrue()
        ->and(Attendance::query()->whereKey($attendance->id)->exists())->toBeTrue();
});

it('refuses to delete an academic session that an attendance mark still references', function (): void {
    $attendance = recordedAttendance();
    $sessionId = $attendance->academic_session_id;

    expect(fn () => AcademicSession::query()->findOrFail($sessionId)->delete())->toThrow(QueryException::class);

    expect(AcademicSession::query()->whereKey($sessionId)->exists())->toBeTrue();
});

it('refuses to delete a class that an attendance mark still references', function (): void {
    $attendance = recordedAttendance();
    $classId = $attendance->school_class_id;

    expect(fn () => SchoolClass::query()->findOrFail($classId)->delete())->toThrow(QueryException::class);

    expect(SchoolClass::query()->whereKey($classId)->exists())->toBeTrue();
});

it('refuses to delete a section that an attendance mark still references', function (): void {
    $attendance = recordedAttendance();
    $sectionId = $attendance->section_id;

    expect(fn () => Section::query()->findOrFail($sectionId)->delete())->toThrow(QueryException::class);

    expect(Section::query()->whereKey($sectionId)->exists())->toBeTrue();
});

it('nulls recorded_by rather than blocking deletion of the recording user - the historical mark outlives the account', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $context = attendanceContext(1);

    $attendance = app(AttendanceService::class)->create(attendancePayload($context), $admin);

    $admin->delete();

    expect($attendance->refresh()->recorded_by)->toBeNull()
        ->and(Attendance::query()->whereKey($attendance->id)->exists())->toBeTrue();
});

/*
| Historical safety across promotion (Module 15)
*/

it('leaves attendance recorded against the pre-promotion enrollment completely untouched after promotion', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $context = attendanceContext(1);
    $sourceEnrollment = $context['enrollments'][0];

    $attendanceService = app(AttendanceService::class);
    $mark = $attendanceService->create(attendancePayload($context, ['date' => '2026-01-10']), $admin);

    // Promote the student into a new session/class - the identical Module 15 workflow.
    $targetLevel = ClassLevel::factory()->create();
    $targetClass = SchoolClass::factory()->create(['class_level_id' => $targetLevel->id]);
    $targetSection = Section::factory()->within($targetClass, 'A', 'A')->create();
    $targetSession = AcademicSession::factory()->create([
        'start_date' => $context['session']->start_date->addYear(),
        'end_date' => $context['session']->end_date->addYear(),
    ]);

    $promotion = app(PromotionService::class)->promote($sourceEnrollment->student, [
        'source_enrollment_id' => $sourceEnrollment->id,
        'target_academic_session_id' => $targetSession->id,
        'decision' => PromotionDecision::PROMOTED->value,
        'target_school_class_id' => $targetClass->id,
        'target_section_id' => $targetSection->id,
    ], $admin);

    $mark->refresh();

    expect($mark->enrollment_id)->toBe($sourceEnrollment->id)
        ->and($mark->academic_session_id)->toBe($context['session']->id)
        ->and($mark->school_class_id)->toBe($context['schoolClass']->id)
        ->and($mark->section_id)->toBe($context['section']->id)
        ->and($mark->status)->toBe(AttendanceStatus::PRESENT)
        ->and($promotion->target_enrollment_id)->not->toBeNull();
});

it('starts a fresh, empty attendance history for the new enrollment created by promotion', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $context = attendanceContext(1);
    $sourceEnrollment = $context['enrollments'][0];

    app(AttendanceService::class)->create(attendancePayload($context, ['date' => '2026-01-10']), $admin);

    $targetLevel = ClassLevel::factory()->create();
    $targetClass = SchoolClass::factory()->create(['class_level_id' => $targetLevel->id]);
    $targetSection = Section::factory()->within($targetClass, 'A', 'A')->create();
    $targetSession = AcademicSession::factory()->create([
        'start_date' => $context['session']->start_date->addYear(),
        'end_date' => $context['session']->end_date->addYear(),
    ]);

    $promotion = app(PromotionService::class)->promote($sourceEnrollment->student, [
        'source_enrollment_id' => $sourceEnrollment->id,
        'target_academic_session_id' => $targetSession->id,
        'decision' => PromotionDecision::PROMOTED->value,
        'target_school_class_id' => $targetClass->id,
        'target_section_id' => $targetSection->id,
    ], $admin);

    expect(Attendance::query()->where('enrollment_id', $promotion->target_enrollment_id)->count())->toBe(0);

    // The new enrollment can record its own, independent attendance for the new session.
    $newMark = app(AttendanceService::class)->create([
        'enrollment_id' => $promotion->target_enrollment_id,
        'academic_session_id' => $targetSession->id,
        'school_class_id' => $targetClass->id,
        'section_id' => $targetSection->id,
        'date' => $targetSession->start_date->toDateString(),
        'status' => AttendanceStatus::PRESENT->value,
    ], $admin);

    expect($newMark->enrollment_id)->toBe($promotion->target_enrollment_id)
        ->and(Attendance::query()->where('enrollment_id', $sourceEnrollment->id)->count())->toBe(1)
        ->and(Attendance::query()->where('enrollment_id', $promotion->target_enrollment_id)->count())->toBe(1);
});

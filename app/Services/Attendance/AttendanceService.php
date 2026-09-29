<?php

namespace App\Services\Attendance;

use App\Enums\AttendanceStatus;
use App\Enums\Role;
use App\Enums\StaffType;
use App\Enums\TeacherAssignmentStatus;
use App\Exceptions\BusinessRuleViolation;
use App\Models\Attendance;
use App\Models\Enrollment;
use App\Models\TeacherAssignment;
use App\Models\Term;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Recording and amending whether a specific student was present, absent, late or excused on a
 * specific date, against a specific enrollment.
 *
 * SCOPED BY ACTOR, THE IDENTICAL SHAPE MODULE 10's OWN ScoreService ALREADY ESTABLISHES. A
 * holder of attendance.record/attendance.view is not thereby entitled to every mark in the
 * school; teaching staff are additionally scoped to classes they hold an ACTIVE
 * TeacherAssignment INTO - but, unlike Score (scoped by one class SUBJECT), attendance is a
 * whole-class fact with no subject of its own, so the scope here is "holds an active
 * assignment to teach ANY subject in this class, this session" - the class-level scope a
 * per-subject TeacherAssignment can support without inventing a "form teacher" primitive this
 * project's schema does not have. See isAssignedToClass().
 *
 * THIS IS NOT A SECOND ENROLLMENT OR TEACHER-ASSIGNMENT SERVICE. Every reference this module
 * writes is validated against data EnrollmentService and TeacherAssignmentService already
 * produced; nothing here creates, amends or re-derives either.
 */
class AttendanceService
{
    /**
     * @var list<string>
     */
    protected const WITH = [
        'enrollment.student',
        'enrollment.academicSession',
        'enrollment.schoolClass',
        'enrollment.section',
        'recordedBy',
    ];

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<array-key, Attendance>
     */
    public function paginate(array $filters, User $user): LengthAwarePaginator
    {
        return $this->query($filters, $user)
            ->orderForDisplay()
            ->paginate(perPage: $filters['per_page'] ?? 15)
            ->withQueryString();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Attendance>
     */
    protected function query(array $filters, User $user): Builder
    {
        $query = Attendance::query()->with(self::WITH);

        // Applied UNCONDITIONALLY, before any filter, so a teacher cannot widen what they see
        // by adding a filter naming a class outside their own assignments - the identical
        // discipline ScoreService::query() already applies (Module 10 audit, "a filter narrows
        // within the scope; it never replaces it").
        if ($user->hasRole(Role::STAFF)) {
            $pairs = $this->activeTeachingClassSessionPairs($user);

            $query->where(function (Builder $scoped) use ($pairs): void {
                if ($pairs->isEmpty()) {
                    $scoped->whereRaw('1 = 0');

                    return;
                }

                foreach ($pairs as $pair) {
                    $scoped->orWhere(function (Builder $q) use ($pair): void {
                        $q->where('school_class_id', $pair['school_class_id'])
                            ->where('academic_session_id', $pair['academic_session_id']);
                    });
                }
            });
        }

        return $query
            ->when($filters['enrollment_id'] ?? null, fn (Builder $q, int $id): Builder => $q->where('attendances.enrollment_id', $id))
            ->when($filters['student_id'] ?? null, fn (Builder $q, int $id): Builder => $q->whereHas(
                'enrollment',
                fn (Builder $qq): Builder => $qq->where('student_id', $id)
            ))
            ->when($filters['school_class_id'] ?? null, fn (Builder $q, int $id): Builder => $q->where('attendances.school_class_id', $id))
            ->when($filters['section_id'] ?? null, fn (Builder $q, int $id): Builder => $q->where('attendances.section_id', $id))
            ->when($filters['academic_session_id'] ?? null, fn (Builder $q, int $id): Builder => $q->where('attendances.academic_session_id', $id))
            ->when($filters['term_id'] ?? null, function (Builder $q, int $id): Builder {
                $term = Term::query()->findOrFail($id);

                return $q->where('attendances.academic_session_id', $term->academic_session_id)
                    ->whereBetween('attendances.date', [$term->start_date->toDateString(), $term->end_date->toDateString()]);
            })
            ->when($filters['date'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate('attendances.date', $date))
            ->when($filters['date_from'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate('attendances.date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate('attendances.date', '<=', $date))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status): Builder => $q->where('attendances.status', $status));
    }

    public function view(Attendance $attendance, User $user): Attendance
    {
        $attendance->loadMissing(self::WITH);

        $this->assertViewable($attendance, $user);

        return $attendance;
    }

    /**
     * Record a single attendance mark.
     *
     * A duplicate (enrollment_id, date) is REJECTED here, never silently updated - see the
     * attendances migration for why this differs from createBulk()'s own upsert behaviour: a
     * client using this endpoint chose to record ONE mark, so a pre-existing one for the same
     * day is a genuine conflict to surface, not a correction to guess at. A real correction
     * uses PUT.
     *
     * @param  array{enrollment_id: int, academic_session_id: int, school_class_id: int, section_id: int, date: string, status: string, remarks?: string|null}  $attributes
     */
    public function create(array $attributes, User $user): Attendance
    {
        $enrollment = Enrollment::query()->findOrFail($attributes['enrollment_id']);

        $this->assertContextMatches($enrollment, $attributes);
        $this->assertTeacherAuthorized($user, (int) $attributes['school_class_id'], (int) $attributes['academic_session_id']);

        try {
            return DB::transaction(function () use ($enrollment, $attributes, $user): Attendance {
                $attendance = new Attendance;

                $attendance->forceFill([
                    'enrollment_id' => $enrollment->id,
                    'academic_session_id' => $enrollment->academic_session_id,
                    'school_class_id' => $enrollment->school_class_id,
                    'section_id' => $enrollment->section_id,
                    'date' => $attributes['date'],
                    'status' => $attributes['status'],
                    'remarks' => $attributes['remarks'] ?? null,
                    'recorded_by' => $user->id,
                ])->save();

                return $attendance->load(self::WITH);
            });
        } catch (QueryException $e) {
            if ($this->isUniqueConstraintViolation($e)) {
                throw new BusinessRuleViolation(
                    'This enrollment already has an attendance record for this date. Use the update endpoint to correct it.',
                    ['enrollment_id' => ['This enrollment already has an attendance record for this date.']],
                    $e,
                );
            }

            throw $e;
        }
    }

    /**
     * Record a whole class register for one date in one call - the natural shape the brief's
     * own workflow produces (Select Class -> Select Section -> Select Date -> Load enrolled
     * students -> Record attendance).
     *
     * Every row is validated FIRST, exactly like ScoreService::createBulk(): if any row is
     * invalid the whole batch is refused with a full per-row error report and NOTHING is
     * written. Only once every row passes does one transaction write the batch.
     *
     * UNLIKE createBulk()'s single-record sibling, an existing mark for (enrollment_id, date)
     * is UPDATED IN PLACE rather than rejected - this is the class-register re-submission a
     * real school day produces (a teacher adds a latecomer, corrects a mistake, and resubmits
     * the same date's register), and it is idempotent: submitting the identical payload twice
     * leaves the same four rows in the same state, never a duplicate. See the attendances
     * migration for why the two entry points deliberately disagree, and
     * AttendanceIntegrityTest for the pinned behaviour.
     *
     * @param  array{academic_session_id: int, school_class_id: int, section_id: int, date: string, attendances: list<array{enrollment_id: int, status: string, remarks?: string|null}>}  $payload
     * @return list<Attendance>
     */
    public function createBulk(array $payload, User $user): array
    {
        $this->assertTeacherAuthorized($user, (int) $payload['school_class_id'], (int) $payload['academic_session_id']);

        $enrollments = Enrollment::query()
            ->whereIn('id', collect($payload['attendances'])->pluck('enrollment_id')->all())
            ->get()
            ->keyBy('id');

        $errors = [];

        foreach ($payload['attendances'] as $index => $row) {
            $enrollment = $enrollments->get($row['enrollment_id']);

            // Existence is already guaranteed by StoreAttendanceBulkRequest's own Rule::exists
            // on attendances.*.enrollment_id; this defends against a row whose enrollment
            // vanished between validation and this call, the same race window ScoreService
            // guards against for its own bulk path.
            if (! $enrollment) {
                $errors["attendances.{$index}.enrollment_id"] = ['This enrollment does not exist.'];

                continue;
            }

            try {
                $this->assertContextMatches($enrollment, $payload);
            } catch (BusinessRuleViolation $e) {
                $errors["attendances.{$index}.enrollment_id"] = [$e->getMessage()];
            }
        }

        if ($errors !== []) {
            throw new BusinessRuleViolation(
                'One or more rows in this batch are invalid. No attendance was recorded.',
                $errors,
            );
        }

        try {
            return DB::transaction(fn (): array => collect($payload['attendances'])
                ->map(function (array $row) use ($payload, $enrollments, $user): Attendance {
                    $enrollment = $enrollments->get($row['enrollment_id']);

                    $attendance = Attendance::query()
                        ->where('enrollment_id', $enrollment->id)
                        ->whereDate('date', $payload['date'])
                        ->first() ?? new Attendance;

                    $attendance->forceFill([
                        'enrollment_id' => $enrollment->id,
                        'academic_session_id' => $enrollment->academic_session_id,
                        'school_class_id' => $enrollment->school_class_id,
                        'section_id' => $enrollment->section_id,
                        'date' => $payload['date'],
                        'status' => $row['status'],
                        'remarks' => $row['remarks'] ?? null,
                        'recorded_by' => $user->id,
                    ])->save();

                    return $attendance->load(self::WITH);
                })
                ->all());
        } catch (QueryException $e) {
            if ($this->isUniqueConstraintViolation($e)) {
                throw new BusinessRuleViolation(
                    'Two concurrent submissions raced for the same attendance record. Please retry this batch.'
                );
            }

            throw $e;
        }
    }

    /**
     * Correct a mark's status or remarks. Nothing else is reachable this way - see
     * UpdateAttendanceRequest. The placement, date and recording context a mark names are
     * fixed for its lifetime; only the outcome itself can be amended, preserving the record
     * rather than replacing it, exactly as the brief's own instruction for corrections asks.
     *
     * @param  array{status?: string, remarks?: string|null}  $attributes
     */
    public function update(Attendance $attendance, array $attributes, User $user): Attendance
    {
        $this->assertTeacherAuthorized($user, $attendance->school_class_id, $attendance->academic_session_id);

        $attendance->forceFill($attributes)->save();

        return $attendance->load(self::WITH);
    }

    /**
     * Present/absent/late/excused counts for one enrollment, optionally narrowed to a term or
     * an explicit date range, plus a percentage.
     *
     * THE DENOMINATOR IS "MARKS ACTUALLY RECORDED FOR THIS ENROLLMENT", NEVER EVERY CALENDAR
     * DAY IN THE WINDOW. This project has no school-calendar/school-day module (explicitly out
     * of scope - see Module 17's own brief), so there is no authoritative source for "how many
     * days SHOULD have been recorded" independent of the marks that were actually taken; using
     * one would silently invent a number this module has no data to justify. When zero marks
     * exist for the window, attendance_percentage is null, NOT 0 - "nothing recorded" and
     * "recorded and always absent" are different facts, and collapsing them would misreport a
     * class no one has taken a register for yet as a class with perfect absenteeism.
     *
     * THE NUMERATOR IS PRESENT ONLY, taken literally from the brief's own formula
     * ("present / applicable recorded attendance days x 100"). LATE and EXCUSED are counted
     * and returned in full, but neither counts toward the percentage - a school that wants a
     * different policy (crediting LATE, or excluding EXCUSED from the denominator) changes it
     * here, in the one place this ratio is computed.
     *
     * @param  array{enrollment_id: int, term_id?: int, date_from?: string, date_to?: string}  $filters
     * @return array{enrollment: Enrollment, total_recorded: int, present: int, absent: int, late: int, excused: int, attendance_percentage: float|null}
     */
    public function summary(array $filters, User $user): array
    {
        $enrollment = Enrollment::query()->findOrFail($filters['enrollment_id']);

        if ($user->hasRole(Role::STAFF) && ! $this->isAssignedToClass($user, $enrollment->school_class_id, $enrollment->academic_session_id)) {
            throw new AuthorizationException(
                'You are not assigned to teach this class, so you cannot view its attendance summary.'
            );
        }

        $query = Attendance::query()->where('enrollment_id', $enrollment->id);

        if (! empty($filters['term_id'])) {
            $term = Term::query()->findOrFail($filters['term_id']);
            $query->whereBetween('date', [$term->start_date->toDateString(), $term->end_date->toDateString()]);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('date', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('date', '<=', $filters['date_to']);
        }

        $counts = $query->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        $present = (int) ($counts[AttendanceStatus::PRESENT->value] ?? 0);
        $absent = (int) ($counts[AttendanceStatus::ABSENT->value] ?? 0);
        $late = (int) ($counts[AttendanceStatus::LATE->value] ?? 0);
        $excused = (int) ($counts[AttendanceStatus::EXCUSED->value] ?? 0);
        $total = $present + $absent + $late + $excused;

        return [
            'enrollment' => $enrollment->loadMissing(['student', 'academicSession', 'schoolClass', 'section']),
            'total_recorded' => $total,
            'present' => $present,
            'absent' => $absent,
            'late' => $late,
            'excused' => $excused,
            'attendance_percentage' => $total > 0 ? round($present / $total * 100, 2) : null,
        ];
    }

    /**
     * The enrollment named in a request must genuinely be the one the request's own class,
     * section and session claim - never trusted merely because all four ids arrived together,
     * the identical IDOR discipline ScoreService::assertContextMatches() already applies. Must
     * also be ACTIVE: a withdrawn or cancelled placement has no current register to add to.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function assertContextMatches(Enrollment $enrollment, array $attributes): void
    {
        if (! $enrollment->isActive()) {
            throw new BusinessRuleViolation(
                'This enrollment has ended, so attendance cannot be recorded against it.'
            );
        }

        if ($enrollment->school_class_id !== (int) $attributes['school_class_id']) {
            throw new BusinessRuleViolation(
                'This enrollment belongs to a different class than the one named in this request.'
            );
        }

        if ($enrollment->section_id !== (int) $attributes['section_id']) {
            throw new BusinessRuleViolation(
                'This enrollment belongs to a different section than the one named in this request.'
            );
        }

        if ($enrollment->academic_session_id !== (int) $attributes['academic_session_id']) {
            throw new BusinessRuleViolation(
                'This enrollment belongs to a different academic session than the one named in this request.'
            );
        }
    }

    /**
     * The gate behind every create(), createBulk() and update(): teaching staff may act only
     * on a class they hold an ACTIVE TeacherAssignment INTO for the stated session - see
     * isAssignedToClass(). Anyone who is NOT role STAFF (SUPER_ADMIN, ADMIN, REGISTRAR) is
     * unrestricted here, matching how every other module in this project treats them once they
     * hold the permission at all.
     */
    protected function assertTeacherAuthorized(User $user, int $schoolClassId, int $academicSessionId): void
    {
        if (! $user->hasRole(Role::STAFF)) {
            return;
        }

        if (! $this->isAssignedToClass($user, $schoolClassId, $academicSessionId)) {
            throw new AuthorizationException(
                'You are not assigned to teach this class, so you cannot record or amend its attendance.'
            );
        }
    }

    protected function assertViewable(Attendance $attendance, User $user): void
    {
        if (! $user->hasRole(Role::STAFF)) {
            return;
        }

        if (! $this->isAssignedToClass($user, $attendance->school_class_id, $attendance->academic_session_id)) {
            throw new AuthorizationException(
                'You are not assigned to teach this class, so you cannot view its attendance.'
            );
        }
    }

    /**
     * Whether this user currently teaches ANY subject in this class, this session - the
     * class-level scope a per-subject TeacherAssignment can support without a "form teacher"
     * primitive this project's schema does not have. Checked as a (school_class_id,
     * academic_session_id) PAIR, matching ScoreService::isAssignedTeacher()'s own reasoning:
     * TeacherAssignment is session-scoped, so who teaches in a class this year says nothing
     * about who taught there last year.
     *
     * False for anyone who is not an actively employed TEACHING staff member, including a
     * NON_TEACHING staff member who happens to hold attendance.* at the role level.
     */
    protected function isAssignedToClass(User $user, int $schoolClassId, int $academicSessionId): bool
    {
        $staff = $user->staff;

        if (! $staff || $staff->staff_type !== StaffType::TEACHING || ! $staff->isActive()) {
            return false;
        }

        return TeacherAssignment::query()
            ->where('teaching_staff_id', $staff->id)
            ->where('academic_session_id', $academicSessionId)
            ->where('status', TeacherAssignmentStatus::ACTIVE->value)
            ->whereHas('classSubject', fn (Builder $q): Builder => $q->where('school_class_id', $schoolClassId))
            ->exists();
    }

    /**
     * Every distinct (school_class_id, academic_session_id) pair this user currently teaches
     * into, for query()'s own list-scoping - the class-level equivalent of ScoreService's
     * activeTeachingAssignments(), collapsed from possibly-many class-subject assignments down
     * to the distinct classes they belong to.
     *
     * @return Collection<int, array{school_class_id: int, academic_session_id: int}>
     */
    protected function activeTeachingClassSessionPairs(User $user): Collection
    {
        $staff = $user->staff;

        if (! $staff || $staff->staff_type !== StaffType::TEACHING || ! $staff->isActive()) {
            return collect();
        }

        return TeacherAssignment::query()
            ->where('teaching_staff_id', $staff->id)
            ->where('status', TeacherAssignmentStatus::ACTIVE->value)
            ->with('classSubject:id,school_class_id')
            ->get()
            ->map(fn (TeacherAssignment $assignment): array => [
                'school_class_id' => $assignment->classSubject->school_class_id,
                'academic_session_id' => $assignment->academic_session_id,
            ])
            ->unique(fn (array $pair): string => "{$pair['school_class_id']}:{$pair['academic_session_id']}")
            ->values();
    }

    /**
     * Whether a QueryException is the unique(enrollment_id, date) index refusing a race,
     * rather than some other integrity failure that should keep propagating as a genuine 500.
     * SQLSTATE 23000 is the portable integrity-violation class across all four configured
     * drivers; this table's only unique index is this one, so any 23000 here is that one - the
     * identical technique every prior module's service uses for its own unique index.
     */
    protected function isUniqueConstraintViolation(QueryException $e): bool
    {
        return $e->getCode() === '23000';
    }
}

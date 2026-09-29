<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use Database\Factories\AttendanceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Whether a specific student was present, absent, late or excused on a specific date, against
 * a specific enrollment.
 *
 * NOT the student's global identity and NOT the enrollment itself - see the attendances
 * migration for the full reasoning, including why academic_session_id/school_class_id/
 * section_id are copied from the enrollment rather than purely derived.
 *
 * enrollment_id, academic_session_id, school_class_id, section_id and date are absent from
 * $fillable-driven amends after creation: AttendanceService sets them once via forceFill(),
 * and no amend ever reaches them - see UpdateAttendanceRequest. Only status and remarks can be
 * corrected once a mark exists.
 *
 * @property int $id
 * @property int $enrollment_id
 * @property int $academic_session_id
 * @property int $school_class_id
 * @property int $section_id
 * @property Carbon $date
 * @property AttendanceStatus $status
 * @property string|null $remarks
 * @property int|null $recorded_by
 */
class Attendance extends Model
{
    /** @use HasFactory<AttendanceFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'status',
        'remarks',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'status' => AttendanceStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Enrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    /**
     * @return BelongsTo<AcademicSession, $this>
     */
    public function academicSession(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class);
    }

    /**
     * @return BelongsTo<SchoolClass, $this>
     */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class);
    }

    /**
     * @return BelongsTo<Section, $this>
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * @param  Builder<Attendance>  $query
     * @return Builder<Attendance>
     */
    public function scopeOrderForDisplay(Builder $query): Builder
    {
        return $query->orderByDesc('date')->orderByDesc('id');
    }
}

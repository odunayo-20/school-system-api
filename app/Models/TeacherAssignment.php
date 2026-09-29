<?php

namespace App\Models;

use App\Enums\TeacherAssignmentStatus;
use Database\Factories\TeacherAssignmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Which teaching staff member is responsible for a class subject, for one academic session.
 *
 * NOT the class subject itself (ClassSubject) and NOT the staff record (Staff) - this row is
 * the relationship between a specific teacher and a specific offering, scoped to a specific
 * session. See the teacher_assignments migration for the full reasoning, in particular why
 * this table is session-scoped when ClassSubject is not, and why at most one row may be
 * ACTIVE for a given (class_subject, session) pair at a time.
 *
 * @property int $id
 * @property int $teaching_staff_id
 * @property int $class_subject_id
 * @property int $academic_session_id
 * @property TeacherAssignmentStatus $status
 * @property string|null $notes
 * @property Carbon|null $ended_at
 */
class TeacherAssignment extends Model
{
    /** @use HasFactory<TeacherAssignmentFactory> */
    use HasFactory;

    /**
     * teaching_staff_id, class_subject_id, academic_session_id and status are all
     * intentionally absent. The three references are set once, by
     * TeacherAssignmentService::create(), via forceFill() - which bypasses this list
     * entirely - and no amend ever reaches them; see UpdateTeacherAssignmentRequest. status
     * changes only through end()/cancel(), also via forceFill(). Listing any of them here
     * would invite a future caller to fill() them on an amend, which is exactly the
     * silent-repoint this model exists to prevent - the identical protection
     * Enrollment::$fillable and ClassSubject::$fillable already give their own identity
     * fields.
     *
     * @var list<string>
     */
    protected $fillable = [
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TeacherAssignmentStatus::class,
            'active_marker' => 'boolean',
            'ended_at' => 'datetime',
        ];
    }

    /**
     * The teaching staff member responsible. Named for what it is - Staff whose staff_type
     * happens to be TEACHING - rather than a bare "staff" relation, so a reader of this
     * model does not have to guess which kind of Staff record belongs here.
     *
     * @return BelongsTo<Staff, $this>
     */
    public function teachingStaff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'teaching_staff_id');
    }

    /**
     * @return BelongsTo<ClassSubject, $this>
     */
    public function classSubject(): BelongsTo
    {
        return $this->belongsTo(ClassSubject::class);
    }

    /**
     * @return BelongsTo<AcademicSession, $this>
     */
    public function academicSession(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class);
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }
}

<?php

namespace App\Models;

use App\Enums\EnrollmentStatus;
use Database\Factories\EnrollmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One student's authoritative academic placement for one academic session: the class and
 * section they belong to, and nothing else about them.
 *
 * This is NOT the student's identity (Student) and NOT the decision that may have preceded it
 * (Admission). See the enrollments migration for the full reasoning. A student accumulates one
 * row here per academic session they are placed in, and none of those rows is ever repointed
 * at a different student, session, class or section - a movement between them, when a future
 * Promotion module needs one, is a new row, not an edit to this one.
 *
 * @property int $id
 * @property int $student_id
 * @property int $academic_session_id
 * @property int $school_class_id
 * @property int $section_id
 * @property Carbon $enrollment_date
 * @property EnrollmentStatus $status
 * @property string|null $notes
 * @property Carbon|null $status_changed_at
 */
class Enrollment extends Model
{
    /** @use HasFactory<EnrollmentFactory> */
    use HasFactory;

    /**
     * student_id, academic_session_id, school_class_id and section_id are NOT here. They are
     * the authoritative placement itself, set once by EnrollmentService::create() and never
     * amended - see UpdateEnrollmentRequest for why a PUT has no key for any of them. Allowing
     * them to be mass-assigned later, even by a service method, would make "which fields does
     * an amend actually touch" a question you have to trace through the request layer to
     * answer rather than read off this list.
     *
     * status is likewise absent, for the identical reason Admission's is present but no
     * request exposes it: EnrollmentService sets it directly (via forceFill on the two
     * transitions, and as a literal on create()), and neither request ever accepts a "status"
     * key. Compare Admission::$fillable, which DOES list status because AdmissionService
     * mass-assigns it on create() the ordinary way; this model's create() path uses forceFill
     * throughout instead, so there is no ordinary mass-assignment call that needs it listed.
     *
     * @var list<string>
     */
    protected $fillable = [
        'enrollment_date',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enrollment_date' => 'date',
            'status' => EnrollmentStatus::class,
            'status_changed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
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

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }
}

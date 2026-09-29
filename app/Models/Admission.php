<?php

namespace App\Models;

use App\Enums\AdmissionStatus;
use App\Enums\Gender;
use Database\Factories\AdmissionFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One applicant's admission decision: who applied, for which intake, and what the school
 * decided.
 *
 * NOT a pupil's identity and NOT an enrollment. See the admissions migration for the full
 * reasoning. In short: `first_name` etc. here are a snapshot of the applicant, kept only
 * because before a decision there is no Student row to point at; `entry_class_level_id`
 * records what the applicant is seeking, never an authoritative placement.
 *
 * @property int $id
 * @property int|null $student_id
 * @property int $academic_session_id
 * @property int|null $entry_class_level_id
 * @property string|null $admission_number
 * @property string $first_name
 * @property string|null $middle_name
 * @property string|null $last_name
 * @property Carbon|null $date_of_birth
 * @property Gender|null $gender
 * @property AdmissionStatus $status
 * @property string|null $notes
 * @property Carbon|null $decided_at
 */
class Admission extends Model
{
    /** @use HasFactory<AdmissionFactory> */
    use HasFactory;

    /**
     * student_id is NOT here, on purpose. See the migration: it is written exactly once, by
     * AdmissionService::admit(), via forceFill() - which bypasses this list entirely, the same
     * way StudentService links nothing here either. No request in this module ever reaches it.
     * The absence of the key from every request's accepted fields - not a validation rule
     * rejecting it, and not this list - is what stops a holder of admissions.update from
     * linking an admission to an arbitrary pupil. See StoreAdmissionRequest and
     * UpdateAdmissionRequest.
     *
     * status IS listed, matching Student::$fillable rather than excluding it: AdmissionService
     * sets it via ordinary mass assignment on create() and via forceFill() on every
     * transition, and StoreAdmissionRequest/UpdateAdmissionRequest simply never expose a
     * "status" key for a client to send. The protection is the request's accepted-field list,
     * not this one - the identical layering Module 04 uses for students.status.
     *
     * @var list<string>
     */
    protected $fillable = [
        'admission_number',
        'academic_session_id',
        'entry_class_level_id',
        'first_name',
        'middle_name',
        'last_name',
        'date_of_birth',
        'gender',
        'status',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'gender' => Gender::class,
            'status' => AdmissionStatus::class,
            'decided_at' => 'datetime',
        ];
    }

    /**
     * Normalise the admission number the same way Student::studentNumber() does, for the
     * same reason: the request folds it before the uniqueness check, and this is the second
     * line of defence for any writer that does not pass through a request.
     *
     * @return Attribute<string, string|null>
     */
    protected function admissionNumber(): Attribute
    {
        return Attribute::make(
            set: function (?string $value): ?string {
                if (is_null($value)) {
                    return null;
                }

                $normalised = mb_strtoupper(trim($value));

                return $normalised === '' ? null : $normalised;
            },
        );
    }

    /**
     * @return Attribute<string, string|null>
     */
    protected function firstName(): Attribute
    {
        return $this->trimmedNamePart();
    }

    /**
     * @return Attribute<string, string|null>
     */
    protected function middleName(): Attribute
    {
        return $this->trimmedNamePart();
    }

    /**
     * @return Attribute<string, string|null>
     */
    protected function lastName(): Attribute
    {
        return $this->trimmedNamePart();
    }

    /**
     * The pupil this admission created, once ADMITTED. Null until then.
     *
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * The intake this admission is for.
     *
     * @return BelongsTo<AcademicSession, $this>
     */
    public function academicSession(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class);
    }

    /**
     * The level the applicant is seeking. Not an enrollment.
     *
     * @return BelongsTo<ClassLevel, $this>
     */
    public function entryClassLevel(): BelongsTo
    {
        return $this->belongsTo(ClassLevel::class);
    }

    /**
     * The applicant's name as one string, matching Student::fullName() - a method rather
     * than an accessor so it does not appear as a phantom attribute the resource has to
     * remember to override.
     */
    public function fullName(): string
    {
        return collect([$this->first_name, $this->middle_name, $this->last_name])
            ->filter()
            ->implode(' ');
    }

    public function isPending(): bool
    {
        return $this->status->isPending();
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    /**
     * @return Attribute<string, string|null>
     */
    protected function trimmedNamePart(): Attribute
    {
        return Attribute::make(
            set: function (?string $value): ?string {
                if (is_null($value)) {
                    return null;
                }

                $trimmed = trim($value);

                return $trimmed === '' ? null : $trimmed;
            },
        );
    }
}

<?php

namespace App\Http\Requests\Enrollment\Concerns;

use App\Enums\AcademicSessionStatus;
use App\Enums\CatalogStatus;
use App\Enums\StudentStatus;
use Illuminate\Validation\Rule;

/**
 * The shape of an enrollment payload.
 *
 * Split into two rule sets rather than one shared block, because create and amend do NOT
 * accept the same fields - unlike every prior module's Store/Update pair, which shared one
 * full rule set and differed only in whether a status field was added. Here the four fields
 * that name the placement (enrollmentReferenceRules) are Store-only: UpdateEnrollmentRequest
 * never calls that method, because student_id/academic_session_id/school_class_id/section_id
 * are immutable once an enrollment exists - see EnrollmentService::update() and the
 * enrollments migration. enrollmentDetailRules() is the part both requests share.
 */
trait ValidatesEnrollmentRecord
{
    /**
     * The placement itself. Store-only.
     *
     * Every reference is checked against the database, not merely "is this an integer" -
     * per field:
     *
     *  - student_id: must exist and the student must be ACTIVE. This is Module 04's own
     *    lifecycle enum, read directly rather than re-derived; INACTIVE, GRADUATED and
     *    WITHDRAWN are all refused uniformly, and a school wanting to enroll an INACTIVE
     *    pupil reactivates them through Module 04 first rather than this module growing a
     *    second opinion about a status it does not own.
     *
     *  - academic_session_id: must exist and must not be COMPLETED - the identical technique
     *    ValidatesAdmissionRecord uses for the same reason: a completed session is history,
     *    and placing a student into a year that has already run is not a real operation.
     *
     *  - school_class_id: must exist and be ACTIVE (CatalogStatus::ACTIVE, "selectable").
     *    The class's own class LEVEL being active is a second check this rule cannot express
     *    without a join, so it lives in EnrollmentService::assertClassLevelActive() instead -
     *    the same layering TermService uses for a check that needs a loaded relation.
     *
     *  - section_id: must exist, be ACTIVE, and belong to the SAME class named by
     *    school_class_id. The scoping is declarative - Rule::exists()->where() against the
     *    section's own school_class_id column - so a section from an unrelated class is
     *    rejected here, at validation, never trusted from the client and never reaching the
     *    service layer as a "believe the caller" join.
     *
     *  - student_id also carries the "one enrollment per student per session" rule, scoped to
     *    the academic_session_id in the SAME request - the identical pattern
     *    ValidatesTerm::termNumberUniqueness() uses to scope term_number to its session. The
     *    database's own unique(student_id, academic_session_id) index is the backstop for a
     *    genuine race between two requests that both pass this check; see
     *    EnrollmentService::create().
     *
     * @return array<string, mixed>
     */
    protected function enrollmentReferenceRules(): array
    {
        return [
            'student_id' => [
                'required',
                'integer',
                Rule::exists('students', 'id')->where('status', StudentStatus::ACTIVE->value),
                Rule::unique('enrollments', 'student_id')
                    ->where(fn ($query) => $query->where('academic_session_id', $this->input('academic_session_id'))),
            ],
            'academic_session_id' => [
                'required',
                'integer',
                Rule::exists('academic_sessions', 'id')
                    ->whereNot('status', AcademicSessionStatus::COMPLETED->value),
            ],
            'school_class_id' => [
                'required',
                'integer',
                Rule::exists('classes', 'id')->where('status', CatalogStatus::ACTIVE->value),
            ],
            'section_id' => [
                'required',
                'integer',
                Rule::exists('sections', 'id')
                    ->where('status', CatalogStatus::ACTIVE->value)
                    ->where('school_class_id', $this->input('school_class_id')),
            ],
        ];
    }

    /**
     * The fields both Store and Update accept. enrollment_date is required on both: this is a
     * whole-write for the part of the record that IS amendable, following the same
     * "the meaningful field is always explicit" convention as every other module's PUT.
     *
     * The session-range check (the date must fall within the named session's calendar) needs
     * a loaded AcademicSession and lives in EnrollmentService, not here - on create the
     * session comes from academic_session_id in the same request; on amend it comes from the
     * enrollment's own, immutable academic_session_id.
     *
     * @return array<string, mixed>
     */
    protected function enrollmentDetailRules(): array
    {
        return [
            'enrollment_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function enrollmentMessages(): array
    {
        return [
            'student_id.required' => 'A student is required.',
            'student_id.exists' => 'This student does not exist or is not active.',
            'student_id.unique' => 'This student already has an enrollment for this academic session.',
            'academic_session_id.required' => 'An academic session is required.',
            'academic_session_id.exists' => 'This academic session does not exist or is no longer open for enrollment.',
            'school_class_id.required' => 'A class is required.',
            'school_class_id.exists' => 'This class does not exist or is not active.',
            'section_id.required' => 'A section is required.',
            'section_id.exists' => 'This section does not exist, is not active, or does not belong to the selected class.',
            'enrollment_date.required' => 'The enrollment date is required.',
            'enrollment_date.date' => 'The enrollment date must be a valid date.',
            'notes.max' => 'Notes may not be longer than 1000 characters.',
        ];
    }
}

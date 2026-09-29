<?php

namespace App\Http\Requests\Staff\Concerns;

use App\Enums\AcademicSessionStatus;
use App\Enums\CatalogStatus;
use App\Enums\EmploymentStatus;
use App\Enums\StaffType;
use App\Enums\TeacherAssignmentStatus;
use Illuminate\Validation\Rule;

/**
 * The shape of a teacher assignment payload.
 *
 * Only Store uses assignmentReferenceRules(): the three references an assignment names
 * (teaching_staff_id, class_subject_id, academic_session_id) are fixed for its lifetime - see
 * TeacherAssignment's own docblock - so UpdateTeacherAssignmentRequest never calls it and has
 * no key for any of them. The identical split ValidatesEnrollmentRecord and
 * ValidatesClassSubjectRecord both use between their own Store-only reference rules and a
 * Store+Update detail rule set.
 */
trait ValidatesTeacherAssignmentRecord
{
    /**
     * The assignment itself. Store-only.
     *
     *  - teaching_staff_id: must exist, have staff_type TEACHING, and be actively employed
     *    (EmploymentStatus::ACTIVE). A single combined exists() rule, matching the identical
     *    technique ValidatesEnrollmentRecord uses for "student exists and is active" - one
     *    query, one message, rather than three separate checks that would need to run in a
     *    particular order to report the right one. The staff member's LOGIN status
     *    (UserStatus) is deliberately not checked here at all: employment eligibility and
     *    login capability are separate facts in this project - see StaffService::deactivate()
     *    - and a suspended account does not stop someone being a teacher of record.
     *
     *  - class_subject_id: must exist and be ACTIVE. Whether its CLASS and that class's CLASS
     *    LEVEL are also active needs loaded relations this rule cannot express without a
     *    join, so that lives in TeacherAssignmentService::assertClassSubjectSelectable()
     *    instead.
     *
     *  - academic_session_id: must exist and must not be COMPLETED - the identical technique
     *    every module since Admission uses for the same reason.
     *
     *  - class_subject_id also carries the "at most one active assignment per class subject
     *    per session" rule, scoped to the academic_session_id in the SAME request and to
     *    status ACTIVE - the identical scoped-uniqueness pattern ValidatesEnrollmentRecord
     *    uses for "one enrollment per student per session". The database's own
     *    unique(class_subject_id, academic_session_id, active_marker) index is the backstop
     *    for a genuine race between two requests that both pass this check; see
     *    TeacherAssignmentService::create().
     *
     * @return array<string, mixed>
     */
    protected function assignmentReferenceRules(): array
    {
        return [
            'teaching_staff_id' => [
                'required',
                'integer',
                Rule::exists('staff', 'id')
                    ->where('staff_type', StaffType::TEACHING->value)
                    ->where('status', EmploymentStatus::ACTIVE->value),
            ],
            'class_subject_id' => [
                'required',
                'integer',
                Rule::exists('class_subjects', 'id')->where('status', CatalogStatus::ACTIVE->value),
                Rule::unique('teacher_assignments', 'class_subject_id')
                    ->where(fn ($query) => $query
                        ->where('academic_session_id', $this->input('academic_session_id'))
                        ->where('status', TeacherAssignmentStatus::ACTIVE->value)),
            ],
            'academic_session_id' => [
                'required',
                'integer',
                Rule::exists('academic_sessions', 'id')
                    ->whereNot('status', AcademicSessionStatus::COMPLETED->value),
            ],
        ];
    }

    /**
     * The field both Store (optional) and Update (optional) accept.
     *
     * @return array<string, mixed>
     */
    protected function assignmentNotesRule(): array
    {
        return ['nullable', 'string', 'max:1000'];
    }

    /**
     * @return array<string, string>
     */
    protected function assignmentMessages(): array
    {
        return [
            'teaching_staff_id.required' => 'A teaching staff member is required.',
            'teaching_staff_id.exists' => 'This staff member does not exist, is not a teacher, or is not actively employed.',
            'class_subject_id.required' => 'A class subject is required.',
            'class_subject_id.exists' => 'This class subject does not exist or is not active.',
            'class_subject_id.unique' => 'This class subject already has an active teacher for this academic session.',
            'academic_session_id.required' => 'An academic session is required.',
            'academic_session_id.exists' => 'This academic session does not exist or is no longer open for new assignments.',
            'notes.max' => 'Notes may not be longer than 1000 characters.',
        ];
    }
}

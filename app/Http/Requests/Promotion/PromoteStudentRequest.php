<?php

namespace App\Http\Requests\Promotion;

use App\Enums\AcademicSessionStatus;
use App\Enums\CatalogStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\PromotionDecision;
use App\Models\Student;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Record a promotion decision for the student named by the route.
 *
 * source_enrollment_id is scoped to the ROUTE's student, not merely validated for existence -
 * the identical IDOR discipline UpdateScoreRequest already applies to its own route-bound
 * record: a client cannot promote student A by naming student A in the URL and someone else's
 * enrollment id in the body, because the exists() rule below only matches a row that is BOTH
 * that enrollment id AND belongs to this exact student.
 *
 * target_school_class_id/target_section_id are required for PROMOTED and PROHIBITED for every
 * other decision - not merely optional. RETAINED's target class/section are always copied from
 * the source enrollment by PromotionService, never accepted from a client; GRADUATED and
 * NOT_ELIGIBLE have no target class at all. Accepting the fields regardless of decision and
 * silently ignoring them would let a client believe a value it sent had an effect it did not.
 */
class PromoteStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Student $student */
        $student = $this->route('student');

        return [
            'source_enrollment_id' => [
                'required',
                'integer',
                Rule::exists('enrollments', 'id')
                    ->where('student_id', $student->id)
                    ->where('status', EnrollmentStatus::ACTIVE->value),
            ],
            'target_academic_session_id' => [
                'required',
                'integer',
                Rule::exists('academic_sessions', 'id')
                    ->whereNot('status', AcademicSessionStatus::COMPLETED->value),
            ],
            'decision' => ['required', 'string', Rule::enum(PromotionDecision::class)],
            'target_school_class_id' => [
                'required_if:decision,'.PromotionDecision::PROMOTED->value,
                'prohibited_unless:decision,'.PromotionDecision::PROMOTED->value,
                'integer',
                Rule::exists('classes', 'id')->where('status', CatalogStatus::ACTIVE->value),
            ],
            'target_section_id' => [
                'required_if:decision,'.PromotionDecision::PROMOTED->value,
                'prohibited_unless:decision,'.PromotionDecision::PROMOTED->value,
                'integer',
                Rule::exists('sections', 'id')
                    ->where('status', CatalogStatus::ACTIVE->value)
                    ->where('school_class_id', $this->input('target_school_class_id')),
            ],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'source_enrollment_id.required' => 'A source enrollment is required.',
            'source_enrollment_id.exists' => 'This enrollment does not exist, does not belong to this student, or is not active.',
            'target_academic_session_id.required' => 'A target academic session is required.',
            'target_academic_session_id.exists' => 'This academic session does not exist or is no longer open.',
            'decision.required' => 'A promotion decision is required.',
            'decision.enum' => 'The decision must be one of: '.implode(', ', PromotionDecision::values()).'.',
            'target_school_class_id.required_if' => 'A target class is required for a PROMOTED decision.',
            'target_school_class_id.prohibited_unless' => 'A target class is only accepted for a PROMOTED decision - RETAINED keeps the source enrollment\'s own class, and GRADUATED/NOT_ELIGIBLE have no target class.',
            'target_school_class_id.exists' => 'This class does not exist or is not active.',
            'target_section_id.required_if' => 'A target section is required for a PROMOTED decision.',
            'target_section_id.prohibited_unless' => 'A target section is only accepted for a PROMOTED decision.',
            'target_section_id.exists' => 'This section does not exist, is not active, or does not belong to the selected target class.',
            'reason.max' => 'The reason may not be longer than 1000 characters.',
        ];
    }

    /**
     * @return array{source_enrollment_id: int, target_academic_session_id: int, decision: string, target_school_class_id?: int, target_section_id?: int, reason?: string|null}
     */
    public function promotionAttributes(): array
    {
        return $this->safe()->only([
            'source_enrollment_id',
            'target_academic_session_id',
            'decision',
            'target_school_class_id',
            'target_section_id',
            'reason',
        ]);
    }
}

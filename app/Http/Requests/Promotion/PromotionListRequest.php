<?php

namespace App\Http\Requests\Promotion;

use App\Enums\PromotionDecision;
use App\Http\Requests\ListRequest;
use Illuminate\Validation\Rule;

/**
 * Filters for the promotion history list: which student, which source enrollment, which
 * target session, and which decision.
 *
 * There is no `search` - a promotion holds no text field of its own to search (`reason` is
 * free-form administrative context, not a lookup field), matching every other list in this API
 * that has none.
 */
class PromotionListRequest extends ListRequest
{
    /**
     * @return array<string, mixed>
     */
    protected function filterRules(): array
    {
        return [
            'student_id' => ['sometimes', 'integer', Rule::exists('students', 'id')],
            'source_enrollment_id' => ['sometimes', 'integer', Rule::exists('enrollments', 'id')],
            'target_academic_session_id' => ['sometimes', 'integer', Rule::exists('academic_sessions', 'id')],
            'decision' => ['sometimes', 'string', Rule::enum(PromotionDecision::class)],
            'search' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'student_id.exists' => 'This student does not exist.',
            'source_enrollment_id.exists' => 'This enrollment does not exist.',
            'target_academic_session_id.exists' => 'This academic session does not exist.',
            'decision.enum' => 'The decision must be one of: '.implode(', ', PromotionDecision::values()).'.',
            'search.prohibited' => 'Promotions cannot be searched by text. Filter by student_id, source_enrollment_id, target_academic_session_id or decision instead.',
        ]);
    }
}

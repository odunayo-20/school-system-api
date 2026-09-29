<?php

namespace App\Http\Requests\Subject;

use App\Enums\CatalogStatus;
use App\Http\Requests\ListRequest;
use Illuminate\Validation\Rule;

/**
 * Filters for the class-subject list: the two questions this table actually answers -
 * "what does this class offer" (school_class_id) and "which classes offer this subject"
 * (subject_id) - plus status.
 *
 * There is no `search`. A class subject holds no text field of its own - see the
 * class_subjects migration - so a text search here could only mean searching the linked
 * subject's name, which would need a join this module's own columns do not support. The
 * identical reasoning ValidatesEnrollmentFilters gives for its own list.
 */
class ClassSubjectListRequest extends ListRequest
{
    /**
     * @return array<string, mixed>
     */
    protected function filterRules(): array
    {
        return [
            'school_class_id' => ['sometimes', 'integer', Rule::exists('classes', 'id')],
            'subject_id' => ['sometimes', 'integer', Rule::exists('subjects', 'id')],
            'status' => ['sometimes', 'string', Rule::enum(CatalogStatus::class)],
            // Overrides the base ListRequest rule for this one key - see
            // ValidatesEnrollmentFilters for why this technique (this array is merged AFTER
            // the base rules) is how a list request without a text field refuses `search`
            // with a clear 422 instead of silently accepting and ignoring it.
            'search' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'school_class_id.exists' => 'This class does not exist.',
            'subject_id.exists' => 'This subject does not exist.',
            'status.enum' => 'The status must be one of: '.implode(', ', CatalogStatus::values()).'.',
            'search.prohibited' => 'Class subjects cannot be searched by text. Filter by school_class_id, subject_id or status instead.',
        ]);
    }
}

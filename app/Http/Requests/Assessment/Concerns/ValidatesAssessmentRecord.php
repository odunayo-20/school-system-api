<?php

namespace App\Http\Requests\Assessment\Concerns;

use App\Enums\CatalogStatus;
use App\Enums\TermStatus;
use Illuminate\Validation\Rule;

/**
 * The shape of an assessment payload.
 *
 * Only Store uses assessmentReferenceRules(): the triple an assessment names
 * (class_subject_id, term_id, assessment_type_id) is fixed for its lifetime - see Assessment's
 * own docblock - so UpdateAssessmentRequest never calls it and has no key for any of them. The
 * identical split ValidatesTeacherAssignmentRecord and ValidatesClassSubjectRecord both use
 * between their own Store-only reference rules and a Store+Update detail rule set.
 */
trait ValidatesAssessmentRecord
{
    /**
     * The assessment's academic context. Store-only.
     *
     *  - class_subject_id: must exist and be ACTIVE. Whether its CLASS and that class's CLASS
     *    LEVEL are also active needs loaded relations this rule cannot express without a join,
     *    so that lives in AssessmentService::assertClassSubjectSelectable() instead.
     *
     *  - term_id: must exist and must not be COMPLETED - the identical technique every module
     *    since Admission uses for the same reason, applied here to a term rather than a
     *    session because that is the granularity this module's academic context is scoped to.
     *
     *  - assessment_type_id: must exist and be ACTIVE (CatalogStatus::ACTIVE, "selectable") -
     *    a retired category cannot be newly used for an assessment.
     *
     * @return array<string, mixed>
     */
    protected function assessmentReferenceRules(): array
    {
        return [
            'class_subject_id' => [
                'required',
                'integer',
                Rule::exists('class_subjects', 'id')->where('status', CatalogStatus::ACTIVE->value),
            ],
            'term_id' => [
                'required',
                'integer',
                Rule::exists('terms', 'id')->whereNot('status', TermStatus::COMPLETED->value),
            ],
            'assessment_type_id' => [
                'required',
                'integer',
                Rule::exists('assessment_types', 'id')->where('status', CatalogStatus::ACTIVE->value),
            ],
        ];
    }

    /**
     * The detail fields both Store and Update accept.
     *
     *  - name: unique within the SAME class subject and term - scoped, not global, because
     *    "CA 1" is a perfectly ordinary name to reuse across different class subjects and
     *    different terms; only a genuine duplicate within the same academic context is
     *    refused. The database's own unique(class_subject_id, term_id, name) index is the
     *    backstop for a genuine race between two requests that both pass this check.
     *
     *  - max_score: must be a positive number. A zero or negative ceiling would make every
     *    future score against this assessment meaningless.
     *
     *  - weight: optional. See the assessments migration for why no rule here enforces that
     *    weights across a class subject/term sum to 100 - that is deliberately not this
     *    module's decision to make.
     *
     * @param  int|null  $classSubjectId  the request's own class_subject_id on Store, or the
     *                                    existing (immutable) record's on Update
     * @param  int|null  $termId  the request's own term_id on Store, or the existing
     *                            (immutable) record's on Update
     * @param  int|null  $ignoreId  the assessment being amended
     * @return array<string, mixed>
     */
    protected function assessmentDetailRules(?int $classSubjectId, ?int $termId, ?int $ignoreId = null): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('assessments', 'name')
                    ->where(fn ($query) => $query
                        ->where('class_subject_id', $classSubjectId)
                        ->where('term_id', $termId))
                    ->ignore($ignoreId),
            ],
            'max_score' => ['required', 'numeric', 'min:0.01', 'max:9999.99'],
            'weight' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'status' => ['sometimes', 'string', Rule::enum(CatalogStatus::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function assessmentMessages(): array
    {
        return [
            'class_subject_id.required' => 'A class subject is required.',
            'class_subject_id.exists' => 'This class subject does not exist or is not active.',
            'term_id.required' => 'A term is required.',
            'term_id.exists' => 'This term does not exist or has already been completed.',
            'assessment_type_id.required' => 'An assessment type is required.',
            'assessment_type_id.exists' => 'This assessment type does not exist or is not active.',
            'name.required' => 'The assessment name is required.',
            'name.unique' => 'An assessment with this name already exists for this class subject and term.',
            'max_score.required' => 'The maximum score is required.',
            'max_score.numeric' => 'The maximum score must be a number.',
            'max_score.min' => 'The maximum score must be greater than zero.',
            'weight.numeric' => 'The weight must be a number.',
            'weight.min' => 'The weight may not be negative.',
            'weight.max' => 'The weight may not be greater than 100.',
            'sort_order.integer' => 'The sort order must be a whole number.',
            'sort_order.min' => 'The sort order may not be negative.',
            'status.enum' => 'The status must be one of: '.implode(', ', CatalogStatus::values()).'.',
        ];
    }
}

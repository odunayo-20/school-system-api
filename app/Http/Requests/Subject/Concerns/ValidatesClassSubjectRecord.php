<?php

namespace App\Http\Requests\Subject\Concerns;

use App\Enums\CatalogStatus;
use Illuminate\Validation\Rule;

/**
 * The shape of a class-subject payload.
 *
 * Only Store uses classSubjectReferenceRules(): the pairing a class subject names is fixed
 * for its lifetime (see ClassSubject's own docblock), so UpdateClassSubjectRequest never
 * calls it and has no key for either reference at all - the identical split
 * ValidatesEnrollmentRecord uses between its own Store-only reference rules and the
 * Store+Update detail rules.
 */
trait ValidatesClassSubjectRecord
{
    /**
     * The offering itself. Store-only.
     *
     *  - school_class_id: must exist and be ACTIVE. The class's own class LEVEL being active
     *    is a second check this rule cannot express without a join, so it lives in
     *    SubjectService::assertClassSelectable() instead.
     *
     *  - subject_id: must exist and be ACTIVE (CatalogStatus::ACTIVE, "selectable") - a
     *    retired subject cannot be newly offered to a class.
     *
     *  - subject_id also carries the "a class should not have the same subject attached
     *    twice" rule, scoped to the school_class_id in the SAME request - the identical
     *    scoped-uniqueness pattern ValidatesEnrollmentRecord uses for "one enrollment per
     *    student per session". The database's own unique(school_class_id, subject_id) index
     *    is the backstop for a genuine race between two requests that both pass this check;
     *    see SubjectService::createClassSubject().
     *
     * @return array<string, mixed>
     */
    protected function classSubjectReferenceRules(): array
    {
        return [
            'school_class_id' => [
                'required',
                'integer',
                Rule::exists('classes', 'id')->where('status', CatalogStatus::ACTIVE->value),
            ],
            'subject_id' => [
                'required',
                'integer',
                Rule::exists('subjects', 'id')->where('status', CatalogStatus::ACTIVE->value),
                Rule::unique('class_subjects', 'subject_id')
                    ->where(fn ($query) => $query->where('school_class_id', $this->input('school_class_id'))),
            ],
        ];
    }

    /**
     * The field both Store (optionally, defaulting to ACTIVE) and Update accept.
     *
     * @return array<string, mixed>
     */
    protected function classSubjectStatusRule(): array
    {
        return ['sometimes', 'string', Rule::enum(CatalogStatus::class)];
    }

    /**
     * @return array<string, string>
     */
    protected function classSubjectMessages(): array
    {
        return [
            'school_class_id.required' => 'A class is required.',
            'school_class_id.exists' => 'This class does not exist or is not active.',
            'subject_id.required' => 'A subject is required.',
            'subject_id.exists' => 'This subject does not exist or is not active.',
            'subject_id.unique' => 'This subject is already attached to this class.',
            'status.enum' => 'The status must be one of: '.implode(', ', CatalogStatus::values()).'.',
        ];
    }
}

<?php

namespace App\Http\Requests\Admission\Concerns;

use App\Enums\AdmissionStatus;
use Illuminate\Validation\Rule;

/**
 * The shared parts of the admission list filters.
 *
 * search covers the admission number and all three snapshot name parts, on the admissions
 * table alone - no join, for the same reason the student roll search needs none: the
 * applicant's name is on the admission row itself.
 *
 * status, academic_session_id and entry_class_level_id are the three questions a registrar
 * actually works a queue by: "what's outstanding", "for this intake", "for this level". There
 * is no date-range filter - nothing in this module's approved scope asked for one, and
 * created_at ordering already answers "what came in lately" (see AdmissionService::paginate).
 */
trait ValidatesAdmissionFilters
{
    /**
     * @return array<string, mixed>
     */
    protected function admissionFilterRules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::enum(AdmissionStatus::class)],
            'academic_session_id' => ['sometimes', 'integer', Rule::exists('academic_sessions', 'id')],
            'entry_class_level_id' => ['sometimes', 'integer', Rule::exists('class_levels', 'id')],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function admissionFilterMessages(): array
    {
        return [
            'status.enum' => 'The status must be one of: '.implode(', ', AdmissionStatus::values()).'.',
            'academic_session_id.exists' => 'This academic session does not exist.',
            'entry_class_level_id.exists' => 'This class level does not exist.',
        ];
    }
}

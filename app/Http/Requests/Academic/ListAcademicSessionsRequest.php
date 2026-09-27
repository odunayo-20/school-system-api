<?php

namespace App\Http\Requests\Academic;

use App\Enums\AcademicSessionStatus;
use Illuminate\Validation\Rule;

/**
 * Filters for the academic session list.
 */
class ListAcademicSessionsRequest extends AcademicListRequest
{
    /**
     * @return array<string, mixed>
     */
    protected function filterRules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::enum(AcademicSessionStatus::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'status.enum' => 'The status must be one of: '.implode(', ', AcademicSessionStatus::values()).'.',
        ]);
    }
}

<?php

namespace App\Http\Requests\Academic;

use App\Enums\TermStatus;
use App\Http\Requests\ListRequest;
use Illuminate\Validation\Rule;

/**
 * Filters for the terms of one academic session.
 */
class ListTermsRequest extends ListRequest
{
    /**
     * @return array<string, mixed>
     */
    protected function filterRules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::enum(TermStatus::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'status.enum' => 'The status must be one of: '.implode(', ', TermStatus::values()).'.',
        ]);
    }
}

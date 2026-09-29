<?php

namespace App\Http\Requests\Admission;

use App\Http\Requests\Admission\Concerns\ValidatesAdmissionFilters;
use App\Http\Requests\ListRequest;

/**
 * Filters for the admission list. See ValidatesAdmissionFilters for what each one answers
 * and why there are only three beyond the inherited search/per_page.
 */
class AdmissionListRequest extends ListRequest
{
    use ValidatesAdmissionFilters;

    /**
     * @return array<string, mixed>
     */
    protected function filterRules(): array
    {
        return $this->admissionFilterRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), $this->admissionFilterMessages());
    }
}

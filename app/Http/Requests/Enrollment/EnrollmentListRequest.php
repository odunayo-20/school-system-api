<?php

namespace App\Http\Requests\Enrollment;

use App\Http\Requests\Enrollment\Concerns\ValidatesEnrollmentFilters;
use App\Http\Requests\ListRequest;

/**
 * Filters for the enrollment list. See ValidatesEnrollmentFilters for what each one answers
 * and why there is no `search`.
 */
class EnrollmentListRequest extends ListRequest
{
    use ValidatesEnrollmentFilters;

    /**
     * @return array<string, mixed>
     */
    protected function filterRules(): array
    {
        return $this->enrollmentFilterRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), $this->enrollmentFilterMessages());
    }
}

<?php

namespace App\Http\Requests\Staff;

use App\Http\Requests\ListRequest;
use App\Http\Requests\Staff\Concerns\ValidatesStaffFilters;

/**
 * Filters for the staff list.
 *
 * search covers the staff number and, because the person's name and email live on users
 * rather than on staff, the linked account's name and email too. A registrar looking for
 * "who works here called Amina" should not have to know which table a field is in.
 */
class StaffListRequest extends ListRequest
{
    use ValidatesStaffFilters;

    /**
     * @return array<string, mixed>
     */
    protected function filterRules(): array
    {
        return $this->staffFilterRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), $this->staffFilterMessages());
    }
}

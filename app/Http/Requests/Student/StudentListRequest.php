<?php

namespace App\Http\Requests\Student;

use App\Http\Requests\ListRequest;
use App\Http\Requests\Student\Concerns\ValidatesStudentFilters;

/**
 * Filters for the pupil list.
 *
 * search covers the student number and all three name parts, and unlike Module 03's staff
 * search it touches one table. A registrar looking for "the child called Amina" should not
 * have to know which table a field is in, and here the answer is: whichever one the record
 * itself is in.
 */
class StudentListRequest extends ListRequest
{
    use ValidatesStudentFilters;

    /**
     * @return array<string, mixed>
     */
    protected function filterRules(): array
    {
        return $this->studentFilterRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), $this->studentFilterMessages());
    }
}

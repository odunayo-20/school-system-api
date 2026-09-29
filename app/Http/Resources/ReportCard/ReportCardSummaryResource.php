<?php

namespace App\Http\Resources\ReportCard;

use App\Http\Resources\Academic\TermResource;
use App\Http\Resources\EnrollmentResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of a student's report-card history: which enrollment, which term, and the same
 * summary figures the full card exposes - never the per-subject breakdown, which a client
 * fetches through GET /report-cards/enrollments/{enrollment}/terms/{term} using the
 * enrollment/term ids this row already carries.
 *
 * Wraps the plain array ReportCardService::paginateForStudent() builds per row.
 */
class ReportCardSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'enrollment' => new EnrollmentResource($this->resource['enrollment']),
            'term' => new TermResource($this->resource['term']),
            'subjects_count' => $this->resource['subjects_count'],
            'overall_percentage' => $this->resource['overall_percentage'],
            'average_grade_point' => $this->resource['average_grade_point'],
        ];
    }
}

<?php

namespace App\Http\Resources\ReportCard;

use App\Http\Resources\Academic\TermResource;
use App\Http\Resources\ClassSubjectResource;
use App\Http\Resources\EnrollmentResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A student's full report card for one enrollment and one term: every finalized (PUBLISHED or
 * LOCKED) subject result, plus a summary.
 *
 * Wraps the plain array ReportCardService::forEnrollmentAndTerm() returns - not an Eloquent
 * model - so every key below is read from that array explicitly rather than through a mixin.
 *
 * `enrollment` reuses EnrollmentResource wholesale (which already nests student,
 * academic_session, school_class and section) rather than flattening those four pieces at
 * this resource's own top level. Re-flattening them here would be a second, divergent field
 * list for data EnrollmentResource already owns - exactly what every resource in this project
 * avoids for its own nested records.
 *
 * DELIBERATELY NO per-assessment breakdown on any subject row, and no `comments` key. See
 * ResultResource's own identical reasoning for the first (Score remains the sole authoritative
 * source; a client calls GET /scores for the breakdown) and the Module 14 audit §14 for the
 * second (no comment system exists anywhere in this project to expose).
 */
class ReportCardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'enrollment' => new EnrollmentResource($this->resource['enrollment']),
            'term' => new TermResource($this->resource['term']),

            'subjects' => collect($this->resource['results'])->map(fn ($result): array => [
                'result_id' => $result->id,
                'class_subject' => new ClassSubjectResource($result->classSubject),
                'percentage' => $result->percentage,
                'grade' => $result->grade,
                'grade_point' => $result->grade_point,
                'remark' => $result->remark,
                'status' => $result->status->value,
            ])->all(),

            'summary' => $this->resource['summary'],
        ];
    }
}

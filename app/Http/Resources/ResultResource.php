<?php

namespace App\Http\Resources;

use App\Http\Resources\Academic\TermResource;
use App\Models\Result;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One compiled subject result: which enrollment, which class subject, which term, and the
 * derived percentage, grade, grade point and remark.
 *
 * There is deliberately no assessment/score breakdown here - see the results migration for
 * why Assessment Scores remain the sole authoritative raw input. A client wanting the rows
 * behind this result calls GET /scores with the same enrollment_id, subject_id and term_id
 * filters Module 10 already exposes, rather than this module duplicating that data.
 *
 * Every related record is rendered through its own module's resource - EnrollmentResource
 * (itself nesting StudentResource/AcademicSessionResource/SchoolClassResource/SectionResource),
 * ClassSubjectResource (itself nesting SchoolClassResource/SubjectResource), TermResource - so
 * this module maintains no second, divergent field list for any of them.
 *
 * This module does NOT implement grading letters, GPA compilation or report cards - grade,
 * grade_point and remark here are simply whatever Module 11's grading scale resolved, copied
 * through unchanged.
 *
 * Since Module 13, also the approval/publication workflow: submitted/approved/published/locked,
 * each a small {id, name} actor projection - never the full UserResource, which would leak an
 * approver's email and permission list to everyone who can merely view the result they acted
 * on. All four stay null until their own transition happens; see ResultService.
 *
 * @mixin Result
 */
class ResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'enrollment' => $this->whenLoaded('enrollment', fn () => new EnrollmentResource($this->enrollment)),
            'class_subject' => $this->whenLoaded('classSubject', fn () => new ClassSubjectResource($this->classSubject)),
            'term' => $this->whenLoaded('term', fn () => new TermResource($this->term)),

            'percentage' => $this->percentage,
            'grade' => $this->grade,
            'grade_point' => $this->grade_point,
            'remark' => $this->remark,
            'status' => $this->status->value,

            'submitted_by' => $this->whenLoaded('submittedBy', fn () => $this->actor($this->submittedBy)),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'approved_by' => $this->whenLoaded('approvedBy', fn () => $this->actor($this->approvedBy)),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'published_by' => $this->whenLoaded('publishedBy', fn () => $this->actor($this->publishedBy)),
            'published_at' => $this->published_at?->toIso8601String(),
            'locked_by' => $this->whenLoaded('lockedBy', fn () => $this->actor($this->lockedBy)),
            'locked_at' => $this->locked_at?->toIso8601String(),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{id: int, name: string}|null
     */
    protected function actor(?User $user): ?array
    {
        return $user ? ['id' => $user->id, 'name' => $user->name] : null;
    }
}

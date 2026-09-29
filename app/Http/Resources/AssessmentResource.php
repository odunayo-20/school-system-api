<?php

namespace App\Http\Resources;

use App\Http\Resources\Academic\TermResource;
use App\Models\Assessment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One configured assessment: which class subject, which term, which category, and its own
 * name, score ceiling and weight. Related records are rendered through their own module's
 * resource - ClassSubjectResource (which itself nests SchoolClassResource and
 * SubjectResource), TermResource, AssessmentTypeResource - so this module maintains no second,
 * divergent field list for any of them.
 *
 * max_score and weight are cast through the model as decimal strings and passed through as-is
 * rather than converted to float, the same convention this project has not needed before now:
 * no prior module carries a monetary-shaped decimal, and returning the string preserves the
 * exact scale a client stored rather than risking float rounding in the response.
 *
 * @mixin Assessment
 */
class AssessmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'class_subject' => $this->whenLoaded('classSubject', fn () => new ClassSubjectResource($this->classSubject)),
            'term' => $this->whenLoaded('term', fn () => new TermResource($this->term)),
            'assessment_type' => $this->whenLoaded('assessmentType', fn () => new AssessmentTypeResource($this->assessmentType)),

            'name' => $this->name,
            'max_score' => $this->max_score,
            'weight' => $this->weight,
            'sort_order' => $this->sort_order,
            'status' => $this->status->value,

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

<?php

namespace App\Http\Resources;

use App\Models\Score;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One student's mark against one configured assessment.
 *
 * max_score and score_percentage are NOT columns on this table - see the scores migration.
 * Both are derived here, at read time, from the loaded assessment: max_score is simply
 * assessment.max_score surfaced at the top level so a client does not have to reach into the
 * nested assessment object for the one number every other field on this resource is relative
 * to, and score_percentage is score / max_score computed fresh rather than a value that could
 * silently drift from the assessment's own (editable) max_score if it were ever cached. Guarded
 * against a zero max_score dividing by zero, even though Module 09's own validation already
 * makes that state unreachable through the API - defence in depth costs one comparison.
 *
 * Every related record is rendered through its own module's resource - AssessmentResource
 * (itself nesting ClassSubjectResource/TermResource/AssessmentTypeResource) and
 * EnrollmentResource (itself nesting StudentResource/AcademicSessionResource/
 * SchoolClassResource/SectionResource) - so this module maintains no second, divergent field
 * list for any of them, matching the reuse convention every prior module's resource follows.
 *
 * This module does NOT implement grading. There is deliberately no grade, letter, GPA or
 * pass/fail field here - see Score's own docblock. score_percentage is a raw ratio, not a
 * grading judgement.
 *
 * @mixin Score
 */
class ScoreResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $maxScore = $this->whenLoaded('assessment', fn (): ?string => $this->assessment->max_score);

        return [
            'id' => $this->id,

            'score' => $this->score,
            'max_score' => $maxScore,
            'score_percentage' => $this->whenLoaded('assessment', fn (): ?float => $this->scorePercentage()),
            'remarks' => $this->remarks,

            'assessment' => $this->whenLoaded('assessment', fn () => new AssessmentResource($this->assessment)),
            'enrollment' => $this->whenLoaded('enrollment', fn () => new EnrollmentResource($this->enrollment)),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * score / assessment.max_score as a percentage, rounded to two decimal places. Null,
     * never a division-by-zero error, when the assessment's max_score is not a positive
     * number - a state Module 09's own validation already prevents on any assessment reachable
     * through the API, but this resource does not assume that holds for every row forever.
     */
    protected function scorePercentage(): ?float
    {
        $maxScore = (float) $this->assessment->max_score;

        if ($maxScore <= 0) {
            return null;
        }

        return round(((float) $this->score / $maxScore) * 100, 2);
    }
}

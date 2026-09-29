<?php

namespace App\Http\Requests\Score;

use App\Http\Requests\Score\Concerns\ValidatesScoreRecord;
use App\Models\Score;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Amend a score's mark or remarks. assessment_id and enrollment_id are NOT accepted here at
 * all - see Score's own docblock for why the pair a score names has no path to change once
 * created. This is both a data-integrity and an authorization concern: without this, a PUT
 * could turn "Student A - Math CA" into "Student B - English Exam" by simply naming different
 * ids, silently bypassing the teacher-assignment scope create() enforces on the ORIGINAL
 * assessment.
 *
 * The max_score check is re-run against the route's existing, immutable assessment_id - not a
 * value captured when the score was first created - because Assessment.max_score is itself
 * editable through Module 09's own UpdateAssessmentRequest. See ScoreService::update().
 */
class UpdateScoreRequest extends FormRequest
{
    use ValidatesScoreRecord;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Score $score */
        $score = $this->route('score');

        return [
            'score' => $this->scoreValueRule($score->assessment_id),
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->scoreMessages();
    }

    /**
     * @return array<string, mixed>
     */
    public function scoreAttributes(): array
    {
        return $this->safe()->only(['score', 'remarks']);
    }
}

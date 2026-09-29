<?php

namespace App\Http\Requests\Score\Concerns;

use App\Models\Assessment;

/**
 * The one rule every score payload shares, single or bulk: the mark itself must be
 * non-negative and must not exceed the NAMED ASSESSMENT'S OWN live max_score.
 *
 * The assessment id is passed in rather than read from $this->input('assessment_id') inside
 * the rule itself, so the identical rule serves three different shapes without three
 * near-duplicate copies: StoreScoreRequest (assessment_id is a top-level request field),
 * UpdateScoreRequest (assessment_id is not in the payload at all - it comes from the existing,
 * immutable score being amended), and StoreScoreBulkRequest (one assessment_id at the top of
 * the request governs every row in scores.*.score).
 *
 * The max_score is looked up FRESH on every validation, never trusted from the client and
 * never a value captured earlier - see the scores migration for why this table stores no
 * max_score of its own. A client cannot bypass this by sending a "maximum_score" field of
 * their own; nothing here reads one.
 */
trait ValidatesScoreRecord
{
    /**
     * @return array<int, mixed>
     */
    protected function scoreValueRule(mixed $assessmentId): array
    {
        return [
            'required',
            'numeric',
            'min:0',
            function (string $attribute, mixed $value, \Closure $fail) use ($assessmentId): void {
                $assessment = $assessmentId ? Assessment::query()->find($assessmentId) : null;

                if ($assessment && (float) $value > (float) $assessment->max_score) {
                    $fail("The score must not exceed the assessment's maximum score of {$assessment->max_score}.");
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function scoreMessages(): array
    {
        return [
            'score.required' => 'The score is required.',
            'score.numeric' => 'The score must be a number.',
            'score.min' => 'The score may not be negative.',
            'remarks.max' => 'Remarks may not be longer than 1000 characters.',
        ];
    }
}

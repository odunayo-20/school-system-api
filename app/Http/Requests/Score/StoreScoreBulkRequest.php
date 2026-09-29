<?php

namespace App\Http\Requests\Score;

use App\Enums\CatalogStatus;
use App\Enums\EnrollmentStatus;
use App\Http\Requests\Score\Concerns\ValidatesScoreRecord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Record a batch of scores against ONE assessment - the natural shape a class roster is
 * entered in: one assessment, many students.
 *
 * Every row is validated here BEFORE ScoreService::createBulk() is ever called, so a client
 * sees every problem in the batch at once (Laravel's own array-validation error keys,
 * `scores.0.score`, `scores.1.enrollment_id`, ...), not one row at a time across repeated
 * requests. What this request CANNOT express - whether a row's enrollment belongs to the same
 * class and academic session as the shared assessment - needs loaded relations and is checked
 * a second time in the service, exactly like the single-score path.
 */
class StoreScoreBulkRequest extends FormRequest
{
    use ValidatesScoreRecord;

    /**
     * A generous ceiling above any single class roster, and low enough that one request
     * cannot become an unbounded write - the identical reasoning ListRequest::MAX_PER_PAGE
     * gives its own ceiling.
     */
    public const MAX_ROWS = 100;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $assessmentId = $this->input('assessment_id');

        return [
            'assessment_id' => [
                'required',
                'integer',
                Rule::exists('assessments', 'id')->where('status', CatalogStatus::ACTIVE->value),
            ],
            'scores' => ['required', 'array', 'min:1', 'max:'.self::MAX_ROWS],
            'scores.*.enrollment_id' => [
                'required',
                'integer',
                // A client submitting the same enrollment twice in one batch is refused here,
                // without a query - Laravel's own array-uniqueness rule, checked across the
                // batch rather than against the database.
                'distinct',
                Rule::exists('enrollments', 'id')->where('status', EnrollmentStatus::ACTIVE->value),
                Rule::unique('scores', 'enrollment_id')
                    ->where(fn ($query) => $query->where('assessment_id', $assessmentId)),
            ],
            'scores.*.score' => $this->scoreValueRule($assessmentId),
            'scores.*.remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge($this->scoreMessages(), [
            'assessment_id.required' => 'An assessment is required.',
            'assessment_id.exists' => 'This assessment does not exist or is not active.',
            'scores.required' => 'At least one score is required.',
            'scores.array' => 'Scores must be a list of rows.',
            'scores.min' => 'At least one score is required.',
            'scores.max' => 'A batch may contain at most '.self::MAX_ROWS.' scores.',
            'scores.*.enrollment_id.required' => 'Each row requires an enrollment.',
            'scores.*.enrollment_id.distinct' => 'The same enrollment appears more than once in this batch.',
            'scores.*.enrollment_id.exists' => 'This enrollment does not exist or is not active.',
            'scores.*.enrollment_id.unique' => 'This enrollment already has a score for this assessment.',
        ]);
    }

    /**
     * @return array{assessment_id: int, scores: list<array{enrollment_id: int, score: string|float, remarks?: string|null}>}
     */
    public function bulkAttributes(): array
    {
        return $this->safe()->only(['assessment_id', 'scores']);
    }
}

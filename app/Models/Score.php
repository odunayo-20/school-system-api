<?php

namespace App\Models;

use Database\Factories\ScoreFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a specific student obtained against a specific configured assessment.
 *
 * NOT the assessment itself (Assessment) and NOT the student's global identity (Student) - see
 * the scores migration for the full reasoning, in particular why this row references
 * enrollment_id rather than student_id: a score belongs to the student's authoritative
 * placement for the session the assessment falls in, not merely to the person, so a score from
 * one enrollment can never surface under a later one for the same student.
 *
 * assessment_id and enrollment_id are absent from $fillable-driven amends after creation:
 * ScoreService sets them once via forceFill(), and no amend ever reaches them - see
 * UpdateScoreRequest. The pair this row names is fixed for its lifetime; only the mark itself
 * and its remarks can change.
 *
 * @property int $id
 * @property int $assessment_id
 * @property int $enrollment_id
 * @property string $score
 * @property string|null $remarks
 */
class Score extends Model
{
    /** @use HasFactory<ScoreFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'score',
        'remarks',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // decimal:2, matching Assessment's own max_score/weight cast: presents a
            // consistently-scaled string regardless of how the underlying driver stored it,
            // rather than a float that could carry a binary-rounding surprise.
            'score' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Assessment, $this>
     */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    /**
     * @return BelongsTo<Enrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }
}

<?php

namespace App\Models;

use App\Enums\PromotionDecision;
use Database\Factories\PromotionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One decision about a student's placement going into a new academic session - see the
 * promotions migration for why this is a genuinely new table, not a reuse of `enrollments` or
 * a mutation of the source enrollment.
 *
 * There is no UpdatePromotionRequest and no $fillable-driven amend path at all:
 * PromotionService::promote() is the only writer, via forceFill(), and a promotion decision is
 * a one-shot historical fact exactly like an Admission's decision or a TeacherAssignment's
 * end/cancel - never amended once recorded.
 *
 * @property int $id
 * @property int $source_enrollment_id
 * @property int $target_academic_session_id
 * @property int|null $target_enrollment_id
 * @property PromotionDecision $decision
 * @property string|null $reason
 * @property int|null $decided_by
 * @property Carbon $decided_at
 */
class Promotion extends Model
{
    /** @use HasFactory<PromotionFactory> */
    use HasFactory;

    /**
     * Nothing is mass-assignable. PromotionService::promote() sets every column via
     * forceFill(), the identical discipline Result and Enrollment already apply to their own
     * anchor-style reference and decision fields - there is no ordinary create()/fill() call
     * this list would need to admit anything for.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'decision' => PromotionDecision::class,
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Enrollment, $this>
     */
    public function sourceEnrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class, 'source_enrollment_id');
    }

    /**
     * @return BelongsTo<Enrollment, $this>
     */
    public function targetEnrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class, 'target_enrollment_id');
    }

    /**
     * @return BelongsTo<AcademicSession, $this>
     */
    public function targetAcademicSession(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'target_academic_session_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * @param  Builder<Promotion>  $query
     * @return Builder<Promotion>
     */
    public function scopeOrderForDisplay(Builder $query): Builder
    {
        return $query->orderByDesc('decided_at')->orderByDesc('id');
    }
}

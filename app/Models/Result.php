<?php

namespace App\Models;

use App\Enums\ResultStatus;
use Database\Factories\ResultFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The compiled academic outcome for one student's enrollment, in one class subject, for one
 * term.
 *
 * NOT a Score (the raw input) and NOT a future Result Approval/Publication/Report Card record
 * - see the results migration for the full reasoning, in particular why this row references
 * enrollment_id rather than student_id.
 *
 * enrollment_id, class_subject_id and term_id are absent from $fillable-driven amends after
 * creation: ResultService::compile() sets them once via forceFill(), and no amend ever reaches
 * them - there is no UpdateResultRequest at all, because the only way to change a result is to
 * recompile it (see ResultService), never a raw PUT accepting client-supplied values. The
 * triple this row names is fixed for its lifetime, the identical protection every anchor-style
 * reference field in this project already has.
 *
 * @property int $id
 * @property int $enrollment_id
 * @property int $class_subject_id
 * @property int $term_id
 * @property string $percentage
 * @property string|null $grade
 * @property string|null $grade_point
 * @property string|null $remark
 * @property ResultStatus $status
 */
class Result extends Model
{
    /** @use HasFactory<ResultFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'percentage',
        'grade',
        'grade_point',
        'remark',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'percentage' => 'decimal:2',
            'grade_point' => 'decimal:2',
            'status' => ResultStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Enrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    /**
     * @return BelongsTo<ClassSubject, $this>
     */
    public function classSubject(): BelongsTo
    {
        return $this->belongsTo(ClassSubject::class);
    }

    /**
     * @return BelongsTo<Term, $this>
     */
    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function isLocked(): bool
    {
        return $this->status->isLocked();
    }

    /**
     * @param  Builder<Result>  $query
     * @return Builder<Result>
     */
    public function scopeOrderForDisplay(Builder $query): Builder
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }
}

<?php

namespace App\Models;

use App\Enums\CatalogStatus;
use Database\Factories\AssessmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One configured assessment: "Mathematics - First Term - JSS 2 - CA 1".
 *
 * NOT a score and NOT a result - see the assessments migration for the full reasoning, in
 * particular why this row (not assessment_types.id directly) is what a future score will
 * reference: a score belongs to "CA 1 for Mathematics in JSS 2, First Term", not to "CA" in
 * the abstract.
 *
 * class_subject_id, term_id and assessment_type_id are absent from $fillable-driven amends
 * after creation: AssessmentService::create() sets them once via forceFill(), and no amend
 * ever reaches them - see UpdateAssessmentRequest. The triple this row names is fixed for its
 * lifetime, the identical protection ClassSubject and TeacherAssignment already give their
 * own identity fields.
 *
 * @property int $id
 * @property int $class_subject_id
 * @property int $term_id
 * @property int $assessment_type_id
 * @property string $name
 * @property string $max_score
 * @property string|null $weight
 * @property int $sort_order
 * @property CatalogStatus $status
 */
class Assessment extends Model
{
    /** @use HasFactory<AssessmentFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'max_score',
        'weight',
        'sort_order',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'max_score' => 'decimal:2',
            'weight' => 'decimal:2',
            'sort_order' => 'integer',
            'status' => CatalogStatus::class,
        ];
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

    /**
     * @return BelongsTo<AssessmentType, $this>
     */
    public function assessmentType(): BelongsTo
    {
        return $this->belongsTo(AssessmentType::class);
    }

    /**
     * @param  Builder<Assessment>  $query
     * @return Builder<Assessment>
     */
    public function scopeOrderForDisplay(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * @param  Builder<Assessment>  $query
     * @return Builder<Assessment>
     */
    public function scopeSelectable(Builder $query): Builder
    {
        return $query->where('status', CatalogStatus::ACTIVE->value);
    }
}

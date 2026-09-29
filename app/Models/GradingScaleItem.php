<?php

namespace App\Models;

use Database\Factories\GradingScaleItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One percentage band within a grading scale: "70 to 100 -> A, grade point 5, Excellent".
 *
 * Boundaries are INCLUSIVE at both ends - see the grading_scale_items migration. Every field
 * on this model is set only through GradingService, which validates and (re)writes a whole
 * scale's items together as one group - see ValidatesGradingScaleRecord for why overlap and
 * duplicate-grade checks cannot be expressed as a per-row rule.
 *
 * @property int $id
 * @property int $grading_scale_id
 * @property string $grade
 * @property string $min_percentage
 * @property string $max_percentage
 * @property string|null $grade_point
 * @property string|null $remark
 */
class GradingScaleItem extends Model
{
    /** @use HasFactory<GradingScaleItemFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'grade',
        'min_percentage',
        'max_percentage',
        'grade_point',
        'remark',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'min_percentage' => 'decimal:2',
            'max_percentage' => 'decimal:2',
            'grade_point' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<GradingScale, $this>
     */
    public function gradingScale(): BelongsTo
    {
        return $this->belongsTo(GradingScale::class);
    }

    /**
     * Whether $percentage falls within this band, inclusive at both ends.
     */
    public function covers(float $percentage): bool
    {
        return $percentage >= (float) $this->min_percentage && $percentage <= (float) $this->max_percentage;
    }
}

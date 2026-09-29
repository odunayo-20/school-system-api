<?php

namespace App\Models;

use App\Enums\CatalogStatus;
use App\Models\Concerns\TracksSingleActiveRecord;
use Database\Factories\GradingScaleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A reusable grading scheme, scoped to one educational stage: "Junior Secondary Standard",
 * "Senior Secondary WAEC Style".
 *
 * See the grading_scales migration for why class_level_id is required rather than nullable,
 * and why this table reuses TracksSingleActiveRecord (already used by School, AcademicSession
 * and Term) unchanged: the trait derives active_marker purely from status, and the SCOPING
 * behaviour ("at most one ACTIVE scale per class level", rather than one ACTIVE scale
 * school-wide) comes entirely from this table's own composite unique index, not from the
 * trait. No modification to the trait was needed.
 *
 * class_level_id is absent from $fillable-driven amends after creation: GradingService sets
 * it once via forceFill(), and no amend ever reaches it - see UpdateGradingScaleRequest. The
 * class level a scale applies to is fixed for its lifetime, the identical protection every
 * anchor-style reference field in this project already has.
 *
 * @property int $id
 * @property int $class_level_id
 * @property string $name
 * @property string $code
 * @property int $sort_order
 * @property CatalogStatus $status
 * @property bool|null $active_marker
 */
class GradingScale extends Model
{
    /** @use HasFactory<GradingScaleFactory> */
    use HasFactory, TracksSingleActiveRecord;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'code',
        'sort_order',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'status' => CatalogStatus::class,
            'active_marker' => 'boolean',
        ];
    }

    protected function activeStatusEnum(): string
    {
        return CatalogStatus::class;
    }

    /**
     * @return BelongsTo<ClassLevel, $this>
     */
    public function classLevel(): BelongsTo
    {
        return $this->belongsTo(ClassLevel::class);
    }

    /**
     * The percentage bands that make up this scale. Ordered highest band first - see
     * GradingScaleItem's own docblock for why percentage itself, not a separate sort_order,
     * gives this its display order.
     *
     * @return HasMany<GradingScaleItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(GradingScaleItem::class)->orderByDesc('min_percentage');
    }

    /**
     * @param  Builder<GradingScale>  $query
     * @return Builder<GradingScale>
     */
    public function scopeOrderForDisplay(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * @param  Builder<GradingScale>  $query
     * @return Builder<GradingScale>
     */
    public function scopeSelectable(Builder $query): Builder
    {
        return $query->where('status', CatalogStatus::ACTIVE->value);
    }
}

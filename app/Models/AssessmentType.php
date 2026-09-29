<?php

namespace App\Models;

use App\Enums\CatalogStatus;
use Database\Factories\AssessmentTypeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A reusable assessment category definition: CA, Test, Examination, Project.
 *
 * Structurally a twin of Subject - a flat, globally unique, ordered catalogue retired with
 * CatalogStatus rather than deleted. See the assessment_types migration for why this table
 * holds no class subject, term, score or weight column: this is the category, not any one
 * configured assessment that uses it.
 *
 * @property int $id
 * @property string $name
 * @property string $code
 * @property int $sort_order
 * @property CatalogStatus $status
 */
class AssessmentType extends Model
{
    /** @use HasFactory<AssessmentTypeFactory> */
    use HasFactory;

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
        ];
    }

    /**
     * Every assessment currently configured against this category, or once configured.
     *
     * @return HasMany<Assessment, $this>
     */
    public function assessments(): HasMany
    {
        return $this->hasMany(Assessment::class);
    }

    /**
     * @param  Builder<AssessmentType>  $query
     * @return Builder<AssessmentType>
     */
    public function scopeOrderForDisplay(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * @param  Builder<AssessmentType>  $query
     * @return Builder<AssessmentType>
     */
    public function scopeSelectable(Builder $query): Builder
    {
        return $query->where('status', CatalogStatus::ACTIVE->value);
    }
}

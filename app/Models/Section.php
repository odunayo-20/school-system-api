<?php

namespace App\Models;

use App\Enums\CatalogStatus;
use Database\Factories\SectionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A section (arm) inside a class: Primary 5 has A, B and C.
 *
 * Sections are CLASS-SPECIFIC. Section A in Primary 5 and section A in JSS 1 are
 * unrelated rows that happen to share a name, which is why the unique key is scoped to
 * the parent class. Modelling sections as a reusable entity joined through a pivot would
 * assert an identity that means nothing to a school and would add a join to every query
 * the later student and results modules need. See the Module 02 audit, section D.4.1.
 *
 * @property int $id
 * @property int $school_class_id
 * @property string $name
 * @property string $code
 * @property int $sort_order
 * @property CatalogStatus $status
 */
class Section extends Model
{
    /** @use HasFactory<SectionFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'school_class_id',
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
            'school_class_id' => 'integer',
            'sort_order' => 'integer',
            'status' => CatalogStatus::class,
        ];
    }

    /**
     * @return BelongsTo<SchoolClass, $this>
     */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class);
    }

    /**
     * @param  Builder<Section>  $query
     * @return Builder<Section>
     */
    public function scopeOrderForDisplay(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * @param  Builder<Section>  $query
     * @return Builder<Section>
     */
    public function scopeSelectable(Builder $query): Builder
    {
        return $query->where('status', CatalogStatus::ACTIVE->value);
    }
}

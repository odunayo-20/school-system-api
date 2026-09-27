<?php

namespace App\Models;

use App\Enums\CatalogStatus;
use Database\Factories\SchoolClassFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A class within a class level: "Primary 1" belongs to the "Primary" level.
 *
 * The class is named SchoolClass, not Class, because `class` is a reserved word in PHP
 * and a model literally called Class cannot be referenced as `new Class` or `use
 * App\Models\Class` without escaping. The table keeps the natural plural name `classes`,
 * which is what the domain actually calls it.
 *
 * This model is NOT a section. Primary 5 is a class; Primary 5A is a Section of it.
 *
 * @property int $id
 * @property int $class_level_id
 * @property string $name
 * @property string $code
 * @property int $sort_order
 * @property CatalogStatus $status
 */
class SchoolClass extends Model
{
    /** @use HasFactory<SchoolClassFactory> */
    use HasFactory;

    /**
     * The table name Laravel would inflect from "SchoolClass" is school_classes, which is
     * not the name the rest of the domain (and the school) uses.
     *
     * @var string
     */
    protected $table = 'classes';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'class_level_id',
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
            'class_level_id' => 'integer',
            'sort_order' => 'integer',
            'status' => CatalogStatus::class,
        ];
    }

    /**
     * @return BelongsTo<ClassLevel, $this>
     */
    public function classLevel(): BelongsTo
    {
        return $this->belongsTo(ClassLevel::class);
    }

    /**
     * @return HasMany<Section, $this>
     */
    public function sections(): HasMany
    {
        return $this->hasMany(Section::class);
    }

    /**
     * @param  Builder<SchoolClass>  $query
     * @return Builder<SchoolClass>
     */
    public function scopeOrderForDisplay(Builder $query): Builder
    {
        return $query->orderBy('name');
    }

    /**
     * @param  Builder<SchoolClass>  $query
     * @return Builder<SchoolClass>
     */
    public function scopeSelectable(Builder $query): Builder
    {
        return $query->where('status', CatalogStatus::ACTIVE->value);
    }
}

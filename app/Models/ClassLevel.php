<?php

namespace App\Models;

use App\Enums\CatalogStatus;
use Database\Factories\ClassLevelFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An educational stage: Nursery, Primary, Junior Secondary, Senior Secondary.
 *
 * The stages are data, not code. Nothing in the application switches on a level name, so
 * a school that adds a Pre-School stage inserts a row and it works immediately.
 *
 * @property int $id
 * @property string $name
 * @property string $code
 * @property int $sort_order
 * @property CatalogStatus $status
 */
class ClassLevel extends Model
{
    /** @use HasFactory<ClassLevelFactory> */
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
     * @return HasMany<SchoolClass, $this>
     */
    public function classes(): HasMany
    {
        return $this->hasMany(SchoolClass::class);
    }

    /**
     * @param  Builder<ClassLevel>  $query
     * @return Builder<ClassLevel>
     */
    public function scopeOrderForDisplay(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * @param  Builder<ClassLevel>  $query
     * @return Builder<ClassLevel>
     */
    public function scopeSelectable(Builder $query): Builder
    {
        return $query->where('status', CatalogStatus::ACTIVE->value);
    }
}

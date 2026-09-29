<?php

namespace App\Models;

use App\Enums\CatalogStatus;
use Database\Factories\SubjectFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A reusable academic subject definition: Mathematics, English Language, Biology.
 *
 * Structurally a twin of ClassLevel - a flat, globally unique, ordered catalogue retired
 * with CatalogStatus rather than deleted - and deliberately so. See the subjects migration
 * for why this table holds no class, teacher or assessment column: this is the catalogue
 * entry, not any one class's offering of it.
 *
 * @property int $id
 * @property string $name
 * @property string $code
 * @property int $sort_order
 * @property CatalogStatus $status
 */
class Subject extends Model
{
    /** @use HasFactory<SubjectFactory> */
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
     * Every class that currently offers this subject, or once did.
     *
     * @return HasMany<ClassSubject, $this>
     */
    public function classSubjects(): HasMany
    {
        return $this->hasMany(ClassSubject::class);
    }

    /**
     * @param  Builder<Subject>  $query
     * @return Builder<Subject>
     */
    public function scopeOrderForDisplay(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * @param  Builder<Subject>  $query
     * @return Builder<Subject>
     */
    public function scopeSelectable(Builder $query): Builder
    {
        return $query->where('status', CatalogStatus::ACTIVE->value);
    }
}

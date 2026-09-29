<?php

namespace App\Models;

use App\Enums\CatalogStatus;
use Database\Factories\ClassSubjectFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The offering of a subject to a class: "JSS 2 teaches Mathematics".
 *
 * NOT the subject itself and NOT a teacher assignment. See the class_subjects migration for
 * the full reasoning, in particular why this row - not subjects.id directly - is what a
 * future teacher assignment and a future assessment will reference: a teacher and an
 * assessment both belong to "Mathematics as taught in JSS 2", not to "Mathematics" in the
 * abstract.
 *
 * school_class_id and subject_id are absent from $fillable-driven amends after creation:
 * UpdateClassSubjectRequest accepts only status, so the pairing this row names is fixed for
 * its lifetime. Changing which subject a row means partway through its life would let a
 * future assessment silently start meaning something else without ever being told.
 *
 * @property int $id
 * @property int $school_class_id
 * @property int $subject_id
 * @property CatalogStatus $status
 */
class ClassSubject extends Model
{
    /** @use HasFactory<ClassSubjectFactory> */
    use HasFactory;

    /**
     * school_class_id and subject_id are intentionally absent. They are set once, by
     * SubjectService::createClassSubject(), and no amend ever reaches them - see
     * UpdateClassSubjectRequest. Listing them here would invite a future caller to fill()
     * them on an amend, which is exactly the silent-repoint this model exists to prevent.
     *
     * @var list<string>
     */
    protected $fillable = [
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
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
     * @return BelongsTo<Subject, $this>
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /**
     * @param  Builder<ClassSubject>  $query
     * @return Builder<ClassSubject>
     */
    public function scopeSelectable(Builder $query): Builder
    {
        return $query->where('status', CatalogStatus::ACTIVE->value);
    }
}

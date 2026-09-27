<?php

namespace App\Models;

use App\Enums\AcademicSessionStatus;
use App\Models\Concerns\TracksSingleActiveRecord;
use Database\Factories\AcademicSessionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A school year, e.g. "2026/2027".
 *
 * At most one session is ACTIVE at a time. That is enforced by the active_marker unique
 * index, not only by application code, so a race between two concurrent activate
 * requests cannot produce two active sessions.
 *
 * @property int $id
 * @property string $name
 * @property Carbon $start_date
 * @property Carbon $end_date
 * @property AcademicSessionStatus $status
 * @property bool|null $active_marker
 */
class AcademicSession extends Model
{
    /** @use HasFactory<AcademicSessionFactory> */
    use HasFactory, TracksSingleActiveRecord;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'start_date',
        'end_date',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'status' => AcademicSessionStatus::class,
            'active_marker' => 'boolean',
        ];
    }

    /**
     * @return class-string<AcademicSessionStatus>
     */
    protected function activeStatusEnum(): string
    {
        return AcademicSessionStatus::class;
    }

    /**
     * @return HasMany<Term, $this>
     */
    public function terms(): HasMany
    {
        return $this->hasMany(Term::class);
    }

    /**
     * @param  Builder<AcademicSession>  $query
     * @return Builder<AcademicSession>
     */
    public function scopeOrderByRecency(Builder $query): Builder
    {
        return $query->orderByDesc('start_date');
    }
}

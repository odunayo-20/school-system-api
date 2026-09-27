<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Keeps the "at most one current record" invariant in the database.
 *
 * The schools, academic_sessions and terms tables each carry a nullable active_marker
 * column with a plain UNIQUE index: 1 on the current record, NULL on every other. SQLite,
 * MySQL, PostgreSQL and SQL Server all treat NULL as distinct inside a unique index, so
 * this yields the guarantee without the driver-specific partial or functional index
 * syntax that only some of those support. See the Module 02 audit, section D.4.2.
 *
 * The marker is never accepted from a client: it is absent from $fillable and from every
 * API Resource. It is derived from status here, on the model, which is what makes drift
 * impossible. A raw UPDATE that sets status = 'ACTIVE' without going through Eloquent
 * still trips the unique index rather than silently creating a second active row.
 *
 * Each using model declares which of its status values claims the slot by implementing
 * activeStatusEnum().
 */
trait TracksSingleActiveRecord
{
    /**
     * The status enum whose ACTIVE case claims the single slot on this table.
     *
     * @return class-string
     */
    abstract protected function activeStatusEnum(): string;

    /**
     * Derive the marker from the status on every save, whichever code path set it.
     */
    protected static function bootTracksSingleActiveRecord(): void
    {
        static::saving(function (Model $model): void {
            $model->setAttribute('active_marker', $model->resolveActiveMarker());
        });
    }

    /**
     * True when this record currently holds the active slot.
     */
    public function isCurrent(): bool
    {
        $enum = $this->activeStatusEnum();

        $status = $this->status instanceof $enum
            ? $this->status
            : $enum::tryFrom((string) $this->status);

        return $status?->isActive() ?? false;
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('status', $this->activeStatusEnum()::ACTIVE->value);
    }

    /**
     * The single active record, or null when the school has not started one yet.
     */
    public static function findCurrent(): ?static
    {
        return static::query()->current()->first();
    }

    /**
     * 1 when the status claims the slot, NULL otherwise. NULL is what lets a unique index
     * tolerate any number of non-current records.
     */
    protected function resolveActiveMarker(): ?int
    {
        $enum = $this->activeStatusEnum();

        $status = $this->status instanceof $enum
            ? $this->status
            : $enum::tryFrom((string) $this->status);

        return $status?->isActive() ? 1 : null;
    }
}

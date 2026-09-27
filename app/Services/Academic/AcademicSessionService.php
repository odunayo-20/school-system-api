<?php

namespace App\Services\Academic;

use App\Enums\AcademicSessionStatus;
use App\Exceptions\BusinessRuleViolation;
use App\Models\AcademicSession;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class AcademicSessionService
{
    /**
     * The session that is currently running, or null if the school has not started one.
     */
    public function current(): ?AcademicSession
    {
        return AcademicSession::findCurrent();
    }

    /**
     * @param  array{status?: string, search?: string, per_page?: int}  $filters
     * @return LengthAwarePaginator<array-key, AcademicSession>
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        return $this->query($filters)
            // Recency first, because the question an administrator opens this screen to
            // answer is "what is running now", and the current session is not the newest
            // by name once a future session has been created.
            ->orderByDesc('start_date')
            ->paginate(perPage: $filters['per_page'] ?? 15)
            ->withQueryString();
    }

    /**
     * @param  array{status?: string, search?: string}  $filters
     * @return Builder<AcademicSession>
     */
    protected function query(array $filters): Builder
    {
        return AcademicSession::query()
            ->when($filters['status'] ?? null, fn (Builder $q, string $status): Builder => $q->where('status', $status))
            ->when($filters['search'] ?? null, function (Builder $q, string $search): Builder {
                $term = '%'.mb_strtolower(trim($search)).'%';

                // Lowercased on both sides so the search matches "2026" and "2026/2027"
                // identically whatever the database collation happens to be, which differs
                // between the four supported drivers.
                return $q->whereRaw('lower(name) like ?', [$term]);
            });
    }

    public function find(int $id): AcademicSession
    {
        return AcademicSession::query()->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): AcademicSession
    {
        $attributes['status'] ??= AcademicSessionStatus::UPCOMING;

        return AcademicSession::query()->create($attributes);
    }

    /**
     * Amend a session's name and dates.
     *
     * A COMPLETED session is immutable. It is history: later modules will hold enrollment,
     * results and promotion records inside its date range, and silently moving those dates
     * would rewrite what those records claim happened when. Retiring a session is done by
     * activating the next one, never by editing the old one.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(AcademicSession $session, array $attributes): AcademicSession
    {
        if ($session->status->isCompleted()) {
            throw new BusinessRuleViolation(
                'A completed academic session cannot be modified. Its record is kept for history.'
            );
        }

        $session->fill($attributes)->save();

        return $session;
    }

    /**
     * Delete a session that was never used.
     *
     * Only an UPCOMING session with no terms may be deleted, and only a super administrator
     * may call this at all. Anything that has run is history and is retired by completing
     * it instead of being erased: a completed session's dates are the frame every result,
     * promotion and report card from that year is measured against.
     */
    public function delete(AcademicSession $session): void
    {
        // Checked before status, so the message names the specific obstacle rather than
        // reporting the more general "a completed session cannot be deleted" for a
        // session that is in fact still running.
        if ($session->isCurrent()) {
            throw new BusinessRuleViolation(
                'The current academic session cannot be deleted. Activate another session first.'
            );
        }

        if ($session->status->isCompleted()) {
            throw new BusinessRuleViolation(
                'A completed academic session cannot be deleted. It is kept as history.'
            );
        }

        if ($session->terms()->exists()) {
            throw new BusinessRuleViolation(
                'This academic session already has terms and cannot be deleted. Delete its terms first.'
            );
        }

        $session->delete();
    }

    /**
     * Make a session the current one, completing whichever session was current.
     *
     * A school has exactly one running year, so activating a session necessarily ends the
     * previous one. That is done here rather than refused, because rolling a year forward
     * is a single real-world action ("we have started 2026/2027") and making an
     * administrator perform it as two separate steps invites a window in which two
     * sessions are ACTIVE or none is.
     *
     * The whole transition is one transaction, and the active_marker unique index is the
     * backstop: if two administrators press this at the same moment, one transaction
     * completes and the other is refused by the database rather than leaving the school
     * with two current sessions.
     */
    public function activate(AcademicSession $session): AcademicSession
    {
        if ($session->isCurrent()) {
            return $session;
        }

        if ($session->status->isCompleted()) {
            throw new BusinessRuleViolation(
                'A completed academic session cannot be activated. Create a new session instead.'
            );
        }

        return DB::transaction(function () use ($session): AcademicSession {
            AcademicSession::query()
                ->where('status', AcademicSessionStatus::ACTIVE->value)
                ->whereKeyNot($session->getKey())
                ->get()
                ->each(function (AcademicSession $previous): void {
                    $previous->status = AcademicSessionStatus::COMPLETED;
                    $previous->save();
                });

            $session->status = AcademicSessionStatus::ACTIVE;
            $session->save();

            return $session;
        });
    }
}

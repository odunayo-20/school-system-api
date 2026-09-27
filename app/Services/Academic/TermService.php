<?php

namespace App\Services\Academic;

use App\Enums\TermStatus;
use App\Exceptions\BusinessRuleViolation;
use App\Models\AcademicSession;
use App\Models\Term;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TermService
{
    /**
     * The term that is currently running, or null if none has been activated.
     */
    public function current(): ?Term
    {
        return Term::findCurrent();
    }

    /**
     * Terms of one session, in the order a school counts them out.
     *
     * Ordered by term_number rather than by name or by date: "Second Term" is only a
     * label, and ordering by it would sort the third term before the first on any
     * installation that named its terms differently.
     *
     * @param  array{status?: string, search?: string, per_page?: int}  $filters
     * @return LengthAwarePaginator<array-key, Term>
     */
    public function paginateForSession(AcademicSession $session, array $filters = []): LengthAwarePaginator
    {
        return $this->query($filters)
            // Eager loaded so the payload is the same shape whichever endpoint served it.
            // The resource uses whenLoaded() either way, so this is what makes the field
            // present here rather than merely safe.
            ->with('academicSession')
            ->where('academic_session_id', $session->getKey())
            ->orderBy('term_number')
            ->paginate(perPage: $filters['per_page'] ?? 15)
            ->withQueryString();
    }

    /**
     * @param  array{status?: string, search?: string}  $filters
     * @return Builder<Term>
     */
    protected function query(array $filters): Builder
    {
        return Term::query()
            ->when($filters['status'] ?? null, fn (Builder $q, string $status): Builder => $q->where('status', $status))
            ->when($filters['search'] ?? null, function (Builder $q, string $search): Builder {
                $term = '%'.mb_strtolower(trim($search)).'%';

                return $q->whereRaw('lower(name) like ?', [$term]);
            });
    }

    public function find(int $id): Term
    {
        return Term::query()->with('academicSession')->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(AcademicSession $session, array $attributes): Term
    {
        $this->assertDatesInsideSession($session, $attributes['start_date'] ?? null, $attributes['end_date'] ?? null);

        $attributes['status'] ??= TermStatus::UPCOMING;

        // The session argument is the authority on where the term lives, so it is merged
        // FIRST: a caller that also passed academic_session_id cannot place the term
        // somewhere other than the session it named the method with.
        return Term::query()->create(['academic_session_id' => $session->getKey()] + $attributes);
    }

    /**
     * Amend a term's name, number and dates.
     *
     * A COMPLETED term is immutable for the same reason a completed session is: results,
     * promotion and report cards are filed against it.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(Term $term, array $attributes): Term
    {
        if ($term->status->isCompleted()) {
            throw new BusinessRuleViolation(
                'A completed term cannot be modified. Its record is kept for history.'
            );
        }

        // A term may be moved between sessions only by being deleted and recreated, because
        // its position in the year's sequence is defined by its session. Rejecting the move
        // here is clearer than allowing a term_number to silently change meaning.
        if (array_key_exists('academic_session_id', $attributes)
            && (int) $attributes['academic_session_id'] !== $term->academic_session_id) {
            throw new BusinessRuleViolation(
                'A term cannot be moved to a different academic session. Create it in the correct session instead.'
            );
        }

        $this->assertDatesInsideSession(
            $term->academicSession,
            $attributes['start_date'] ?? $term->start_date,
            $attributes['end_date'] ?? $term->end_date,
        );

        $term->fill($attributes)->save();

        return $term;
    }

    /**
     * Delete a term that has not run.
     *
     * The active term is refused outright: removing it would leave the school with no
     * current term, and every later module that needs "the term a mark belongs to" would
     * have no answer. Completing it and activating the next one is the way forward.
     */
    public function delete(Term $term): void
    {
        if ($term->isCurrent()) {
            throw new BusinessRuleViolation(
                'The current term cannot be deleted. Activate another term first.'
            );
        }

        if ($term->status->isCompleted()) {
            throw new BusinessRuleViolation(
                'A completed term cannot be deleted. It is kept as history.'
            );
        }

        $term->delete();
    }

    /**
     * Make a term the current one, completing whichever term was current.
     *
     * A term may only become current inside the session that is itself current. Allowing a
     * term of last year to be activated would produce a current term that disagrees with
     * the current session, and both are read independently by later modules, so the
     * contradiction would surface far from its cause.
     */
    public function activate(Term $term): Term
    {
        if ($term->isCurrent()) {
            return $term;
        }

        if ($term->status->isCompleted()) {
            throw new BusinessRuleViolation(
                'A completed term cannot be activated. Create a new term instead.'
            );
        }

        $currentSession = AcademicSession::findCurrent();

        if (! $currentSession) {
            throw new BusinessRuleViolation(
                'There is no current academic session. Activate an academic session before activating a term.'
            );
        }

        if ($term->academic_session_id !== $currentSession->getKey()) {
            throw new BusinessRuleViolation(
                "This term belongs to {$term->academicSession->name}, which is not the current academic session. Only a term of the current session can be activated."
            );
        }

        return DB::transaction(function () use ($term): Term {
            Term::query()
                ->where('status', TermStatus::ACTIVE->value)
                ->whereKeyNot($term->getKey())
                ->get()
                ->each(function (Term $previous): void {
                    $previous->status = TermStatus::COMPLETED;
                    $previous->save();
                });

            $term->status = TermStatus::ACTIVE;
            $term->save();

            return $term;
        });
    }

    /**
     * A term's dates must sit inside its session's dates.
     *
     * Enforced here rather than only by the Form Request because the same rule has to hold
     * when the session's own dates are later amended, and because a service is reachable
     * from code that never passed through a request. Without it, "the active term" could
     * fall outside "the active session" and the two would disagree.
     */
    protected function assertDatesInsideSession(AcademicSession $session, mixed $start, mixed $end): void
    {
        if (! $start || ! $end) {
            return;
        }

        $start = Carbon::parse($start);
        $end = Carbon::parse($end);

        if ($start->lt($session->start_date) || $end->gt($session->end_date)) {
            throw new BusinessRuleViolation(
                "The term dates must fall within {$session->name} ({$session->start_date->toDateString()} to {$session->end_date->toDateString()}).",
                [
                    'start_date' => ["The term must start on or after {$session->start_date->toDateString()}."],
                    'end_date' => ["The term must end on or before {$session->end_date->toDateString()}."],
                ],
            );
        }
    }
}

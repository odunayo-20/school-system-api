<?php

namespace App\Services\Academic;

use App\Models\AcademicSession;
use App\Models\School;
use App\Models\Term;

/**
 * The school, the current academic session and the current term, in one read.
 *
 * This exists so a client can render a header like "2026/2027 - First Term" with ONE
 * request instead of three, and so the "which year and term is this school in" question
 * has exactly one answer in the system rather than one per module that guesses.
 *
 * It deliberately does NOT cache. An earlier draft of the Module 02 audit proposed
 * memoising the result per request, with the write services invalidating it. That was
 * dropped: a request is served by a single controller instance, the result is used once,
 * and it costs three indexed lookups against tables that hold a handful of rows. Caching
 * it would buy nothing measurable and would introduce a whole class of bug in which a read
 * that follows a write in the same request returns the pre-write value, because something
 * somewhere failed to call the invalidation. Reading fresh is simpler and cannot go stale.
 */
class AcademicContextService
{
    /**
     * The current academic state.
     *
     * Every part may be null, and the parts are independent: a school can be configured
     * with no session yet, and a session can exist with no active term. Reporting them
     * separately lets a client show an accurate "not started yet" state per level instead
     * of one opaque null, and lets it distinguish "no school configured" (an installation
     * problem) from "no term running" (an ordinary September situation).
     *
     * @return array{
     *     school: array<string, mixed>|null,
     *     session: array<string, mixed>|null,
     *     term: array<string, mixed>|null
     * }
     */
    public function current(): array
    {
        $school = School::current();
        $session = AcademicSession::findCurrent();
        $term = Term::findCurrent();

        return [
            'school' => $school ? [
                'id' => $school->getKey(),
                'name' => $school->name,
                'short_name' => $school->short_name,
                'status' => $school->status->value,
            ] : null,
            'session' => $session ? [
                'id' => $session->getKey(),
                'name' => $session->name,
                'start_date' => $session->start_date->toDateString(),
                'end_date' => $session->end_date->toDateString(),
                'status' => $session->status->value,
            ] : null,
            'term' => $term ? [
                'id' => $term->getKey(),
                'academic_session_id' => $term->academic_session_id,
                'name' => $term->name,
                'term_number' => $term->term_number,
                'start_date' => $term->start_date->toDateString(),
                'end_date' => $term->end_date->toDateString(),
                'status' => $term->status->value,
            ] : null,
        ];
    }

    /**
     * Whether the school has a complete academic state: a profile, a current session and
     * a current term. A client uses this to decide whether to prompt the administrator to
     * finish setting the school up.
     */
    public function isConfigured(): bool
    {
        return School::current() !== null
            && AcademicSession::findCurrent() !== null
            && Term::findCurrent() !== null;
    }
}

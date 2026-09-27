<?php

namespace Database\Seeders;

use App\Enums\AcademicSessionStatus;
use App\Enums\TermStatus;
use App\Models\AcademicSession;
use App\Models\Term;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * The current academic session and its three terms.
 *
 * Dated from today so a fresh clone always opens on a session and term that contain the
 * current date, rather than on a fixed 2024/2025 that is stale on arrival. The session
 * start month comes from config('school.academic_session_start_month').
 *
 * The important property here is that this seeder NEVER moves a school that has already
 * started its year. It is safe to run against a live database:
 *
 *   - If a current session already exists, no second session is created. The unique
 *     active_marker index would reject it anyway, but failing loudly at the first
 *     re-seed is not a good experience, and a seeder should not decide when a school
 *     changes year.
 *   - A term that already exists is left completely alone, including its status, so
 *     re-seeding cannot demote a term the registrar has just activated back to UPCOMING.
 *   - Only a genuinely new session gets its term statuses set, and only the term
 *     containing today is marked ACTIVE.
 */
class AcademicCalendarSeeder extends Seeder
{
    /**
     * The number of terms a school year is divided into.
     */
    protected int $termsPerSession = 3;

    public function run(): void
    {
        $today = Carbon::today();

        [$start, $end] = $this->currentSessionWindow($today);

        $session = AcademicSession::findCurrent() ?? AcademicSession::query()->create([
            'name' => $start->year.'/'.($start->year + 1),
            'start_date' => $start,
            'end_date' => $end,
            'status' => AcademicSessionStatus::ACTIVE,
        ]);

        $windows = $this->termWindows($start, $end);

        foreach ($windows as $index => [$termStart, $termEnd]) {
            $term = Term::query()
                ->where('academic_session_id', $session->getKey())
                ->where('term_number', $index + 1)
                ->first();

            // An existing term belongs to the school now. Leave its name, dates and
            // status exactly as they are.
            if ($term) {
                continue;
            }

            // A new session's term is ACTIVE only if today falls inside it. The others
            // stay UPCOMING so the school activates them in turn.
            $containsToday = $today->betweenIncluded($termStart, $termEnd);

            Term::query()->create([
                'academic_session_id' => $session->getKey(),
                'name' => $this->termName($index),
                'term_number' => $index + 1,
                'start_date' => $termStart,
                'end_date' => $termEnd,
                'status' => $containsToday ? TermStatus::ACTIVE : TermStatus::UPCOMING,
            ]);
        }
    }

    /**
     * The school year that contains today, as a [start, end] date pair.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function currentSessionWindow(Carbon $today): array
    {
        $startMonth = (int) config('school.academic_session_start_month');
        $startMonth = max(1, min(12, $startMonth));

        // Before the start month we are still in the year that began last year.
        $startYear = $today->month >= $startMonth ? $today->year : $today->year - 1;

        $start = Carbon::create($startYear, $startMonth, 1)->startOfDay();
        $end = $start->copy()->addYear()->subDay();

        return [$start, $end];
    }

    /**
     * Split the session into contiguous term windows.
     *
     * The split is on whole days, so the terms tile the session exactly: no gap where a
     * date belongs to no term, and no overlap where a date belongs to two. Anything a
     * later module needs to reason about "which term is this date in" depends on that.
     *
     * @return list<array{0: Carbon, 1: Carbon}>
     */
    protected function termWindows(Carbon $start, Carbon $end): array
    {
        $totalDays = $start->diffInDays($end);
        $chunk = intdiv($totalDays, $this->termsPerSession);

        $windows = [];

        for ($index = 0; $index < $this->termsPerSession; $index++) {
            $termStart = $start->copy()->addDays($index * $chunk);
            $termEnd = $index === $this->termsPerSession - 1
                ? $end->copy()
                : $termStart->copy()->addDays($chunk - 1);

            $windows[] = [$termStart, $termEnd];
        }

        return $windows;
    }

    /**
     * Term names are presentation only. Ordering, comparison and the "one active term"
     * rule all key on term_number, never on this string.
     */
    protected function termName(int $index): string
    {
        return match ($index) {
            0 => 'First Term',
            1 => 'Second Term',
            default => 'Third Term',
        };
    }
}

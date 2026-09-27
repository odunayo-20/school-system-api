<?php

namespace Database\Factories;

use App\Enums\AcademicSessionStatus;
use App\Models\AcademicSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AcademicSession>
 */
class AcademicSessionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startYear = fake()->unique()->numberBetween(1990, 2100);
        $start = fake()->dateTimeBetween('-5 years', '+1 year');

        return [
            'name' => $startYear.'/'.($startYear + 1),
            'start_date' => $start,
            // A session is one year long, inclusive of the start date, so the end date is
            // the day before the anniversary. Anything else overlaps the next session.
            'end_date' => (clone $start)->modify('+1 year -1 day'),
            // UPCOMING by default, for the same reason SchoolFactory is INACTIVE: only one
            // session may be ACTIVE, so a test has to opt in with ->active().
            'status' => AcademicSessionStatus::UPCOMING,
        ];
    }

    /**
     * The current session. Only one of these may exist at a time.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => AcademicSessionStatus::ACTIVE,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => AcademicSessionStatus::COMPLETED,
        ]);
    }

    public function status(AcademicSessionStatus $status): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => $status,
        ]);
    }

    /**
     * A session covering an explicit year, e.g. forSession(2026, 2027).
     */
    public function forYears(int $startYear, int $endYear): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => $startYear.'/'.$endYear,
            'start_date' => "{$startYear}-09-01",
            'end_date' => "{$endYear}-08-31",
        ]);
    }
}

<?php

namespace Database\Factories;

use App\Enums\TermStatus;
use App\Models\AcademicSession;
use App\Models\Term;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Term>
 */
class TermFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = fake()->dateTimeBetween('-6 months', '+6 months');

        return [
            'academic_session_id' => AcademicSession::factory(),
            'name' => fake()->randomElement(['First Term', 'Second Term', 'Third Term']),
            // Defaults to 1 so two terms created for one session in a single test do not
            // collide on the (session, term_number) unique key by accident. Use
            // ->forSession($session, $n) when a test needs more than one.
            'term_number' => 1,
            'start_date' => $start,
            'end_date' => (clone $start)->modify('+3 months'),
            // UPCOMING by default: only one term may be ACTIVE, so a test opts in.
            'status' => TermStatus::UPCOMING,
        ];
    }

    /**
     * The current term. Only one of these may exist at a time.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => TermStatus::ACTIVE,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => TermStatus::COMPLETED,
        ]);
    }

    public function status(TermStatus $status): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => $status,
        ]);
    }

    /**
     * Place the term inside an explicit session at an explicit position.
     */
    public function forSession(AcademicSession $session, int $termNumber): static
    {
        return $this->state(fn (array $attributes): array => [
            'academic_session_id' => $session->getKey(),
            'term_number' => $termNumber,
            'name' => $termNumber.' Term',
        ]);
    }
}

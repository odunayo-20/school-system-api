<?php

namespace Database\Factories;

use App\Enums\CatalogStatus;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subject>
 */
class SubjectFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // unique() over a large generated space, matching ClassLevelFactory's own reason:
            // a small fixed list would exhaust itself and start repeating, tripping the
            // unique name index.
            'name' => fake()->unique()->words(2, true),
            'code' => mb_strtoupper(fake()->unique()->lexify('???')),
            'sort_order' => fake()->numberBetween(1, 20),
            'status' => CatalogStatus::ACTIVE,
        ];
    }

    public function status(CatalogStatus $status): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => $status,
        ]);
    }

    /**
     * A named, coded subject, for the tests that search or filter by one.
     */
    public function named(string $name, string $code): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => $name,
            'code' => $code,
        ]);
    }
}

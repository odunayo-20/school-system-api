<?php

namespace Database\Factories;

use App\Enums\CatalogStatus;
use App\Models\ClassLevel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClassLevel>
 */
class ClassLevelFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // unique() on randomElement() over a small fixed list would exhaust itself
            // after a handful of records and start repeating, which would then trip the
            // unique name index. Generating from a large space instead keeps every
            // factory record distinct.
            'name' => fake()->unique()->words(2, true),
            'code' => mb_strtoupper(fake()->unique()->lexify('????')),
            'sort_order' => fake()->numberBetween(1, 20),
            // Class levels are not a singleton, so ACTIVE is safe as the default.
            'status' => CatalogStatus::ACTIVE,
        ];
    }

    public function status(CatalogStatus $status): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => $status,
        ]);
    }
}

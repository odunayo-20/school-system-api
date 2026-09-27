<?php

namespace Database\Factories;

use App\Enums\CatalogStatus;
use App\Models\ClassLevel;
use App\Models\SchoolClass;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SchoolClass>
 */
class SchoolClassFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'class_level_id' => ClassLevel::factory(),
            'name' => 'Class '.fake()->unique()->numberBetween(1, 100000),
            'code' => mb_strtoupper(fake()->unique()->lexify('??#')),
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
     * A named class inside an explicit level, e.g. ->within($level, 'Primary 1', 'P1').
     */
    public function within(ClassLevel $classLevel, string $name, string $code): static
    {
        return $this->state(fn (array $attributes): array => [
            'class_level_id' => $classLevel->getKey(),
            'name' => $name,
            'code' => $code,
        ]);
    }
}

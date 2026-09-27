<?php

namespace Database\Factories;

use App\Enums\CatalogStatus;
use App\Models\SchoolClass;
use App\Models\Section;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Section>
 */
class SectionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'school_class_id' => SchoolClass::factory(),
            'name' => fake()->randomElement(['A', 'B', 'C']),
            'code' => mb_strtoupper(fake()->unique()->lexify('??')),
            'sort_order' => fake()->numberBetween(1, 10),
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
     * A named section inside an explicit class, e.g. ->within($class, 'A', 'A').
     *
     * Section names are only unique WITHIN their class, so a test that builds the same
     * section name in two different classes needs this rather than two factory records
     * from definition(), which would share a parent and collide.
     */
    public function within(SchoolClass $schoolClass, string $name, string $code): static
    {
        return $this->state(fn (array $attributes): array => [
            'school_class_id' => $schoolClass->getKey(),
            'name' => $name,
            'code' => $code,
        ]);
    }
}

<?php

namespace Database\Factories;

use App\Enums\CatalogStatus;
use App\Models\ClassLevel;
use App\Models\GradingScale;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GradingScale>
 */
class GradingScaleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'class_level_id' => ClassLevel::factory(),
            // unique() over a large generated space, matching every other catalogue
            // factory's own reason: a small fixed list would exhaust itself and start
            // repeating, tripping the unique(class_level_id, name) index.
            'name' => 'Grading Scale '.fake()->unique()->numberBetween(1, 1000000),
            'code' => mb_strtoupper(fake()->unique()->lexify('????')),
            'sort_order' => fake()->numberBetween(1, 20),
            'status' => CatalogStatus::ACTIVE,
        ];
    }

    public function forClassLevel(ClassLevel $classLevel): static
    {
        return $this->state(fn (array $attributes): array => [
            'class_level_id' => $classLevel->getKey(),
        ]);
    }

    public function status(CatalogStatus $status): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => $status,
        ]);
    }

    /**
     * A scale with the standard A-F bands already attached, covering 0-100 with no gaps or
     * overlaps - the shape most tests that do not care about the bands themselves want,
     * matching how activeAssessment()/activeClassSubject() give tests a ready-made valid
     * record rather than making every test wire one up by hand.
     */
    public function configureWithStandardBands(): static
    {
        return $this->afterCreating(function (GradingScale $scale): void {
            $scale->items()->createMany([
                ['grade' => 'A', 'min_percentage' => 70, 'max_percentage' => 100, 'grade_point' => 5, 'remark' => 'Excellent'],
                ['grade' => 'B', 'min_percentage' => 60, 'max_percentage' => 69.99, 'grade_point' => 4, 'remark' => 'Very Good'],
                ['grade' => 'C', 'min_percentage' => 50, 'max_percentage' => 59.99, 'grade_point' => 3, 'remark' => 'Good'],
                ['grade' => 'D', 'min_percentage' => 40, 'max_percentage' => 49.99, 'grade_point' => 2, 'remark' => 'Fair'],
                ['grade' => 'F', 'min_percentage' => 0, 'max_percentage' => 39.99, 'grade_point' => 0, 'remark' => 'Fail'],
            ]);
        });
    }
}

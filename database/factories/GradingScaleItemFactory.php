<?php

namespace Database\Factories;

use App\Models\GradingScale;
use App\Models\GradingScaleItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GradingScaleItem>
 */
class GradingScaleItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'grading_scale_id' => GradingScale::factory(),
            'grade' => mb_strtoupper(fake()->unique()->lexify('?')),
            'min_percentage' => 0,
            'max_percentage' => 100,
            'grade_point' => null,
            'remark' => null,
        ];
    }

    public function forScale(GradingScale $scale): static
    {
        return $this->state(fn (array $attributes): array => [
            'grading_scale_id' => $scale->getKey(),
        ]);
    }

    public function range(float $min, float $max): static
    {
        return $this->state(fn (array $attributes): array => [
            'min_percentage' => $min,
            'max_percentage' => $max,
        ]);
    }
}

<?php

namespace Database\Factories;

use App\Enums\StaffType;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Staff>
 */
class StaffFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => null,
            'staff_type' => StaffType::TEACHING,
            'staff_number' => null,
        ];
    }

    public function teaching(): static
    {
        return $this->state(fn (array $attributes): array => [
            'staff_type' => StaffType::TEACHING,
        ]);
    }

    public function nonTeaching(): static
    {
        return $this->state(fn (array $attributes): array => [
            'staff_type' => StaffType::NON_TEACHING,
        ]);
    }
}

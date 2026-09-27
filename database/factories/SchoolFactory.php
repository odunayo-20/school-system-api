<?php

namespace Database\Factories;

use App\Enums\SchoolStatus;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<School>
 */
class SchoolFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company().' School';

        return [
            'name' => $name,
            'short_name' => mb_strtoupper(fake()->lexify('???')),
            'motto' => fake()->sentence(3),
            'email' => fake()->unique()->companyEmail(),
            'phone' => fake()->numerify('+1#########'),
            'alternate_phone' => null,
            'website' => 'https://'.fake()->domainName(),
            'address_line1' => fake()->streetAddress(),
            'city' => fake()->city(),
            'state' => fake()->word(),
            'country' => fake()->country(),
            'principal_name' => fake()->name(),
            'registration_number' => (string) fake()->unique()->numberBetween(10000, 99999),
            // INACTIVE by default because a school is a singleton. A test that wants the
            // current school has to say so with ->active(), which keeps the
            // active_marker unique index from being violated by an incidental second
            // record, and keeps "which school is current" an explicit decision.
            'status' => SchoolStatus::INACTIVE,
        ];
    }

    /**
     * The current school profile. Only one of these may exist at a time.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => SchoolStatus::ACTIVE,
        ]);
    }

    public function status(SchoolStatus $status): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => $status,
        ]);
    }
}

<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\Role as RoleModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoleModel>
 */
class RoleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => Role::ADMIN->value,
            'label' => 'Administrator',
            'description' => null,
        ];
    }
}

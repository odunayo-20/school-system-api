<?php

namespace Database\Factories;

use App\Models\Permission;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Permission>
 */
class PermissionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $group = 'users';

        return [
            'name' => "{$group}.".Str::random(8),
            'label' => Str::headline(Str::random(8)),
            'description' => null,
        ];
    }
}

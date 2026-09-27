<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Role as RoleModel;
use Illuminate\Database\Seeder;

/**
 * Idempotent: roles are keyed on their unique name, so re-running the seeder can never
 * create a duplicate role.
 */
class RoleSeeder extends Seeder
{
    /**
     * @var array<string, array{label: string, description: string}>
     */
    protected array $roles = [
        Role::SUPER_ADMIN->value => [
            'label' => 'Super Administrator',
            'description' => 'Highest privileged role. Bypasses every authorization check via a single Gate::before callback.',
        ],
        Role::ADMIN->value => [
            'label' => 'Administrator',
            'description' => 'Runs the school day to day and manages user accounts.',
        ],
        Role::REGISTRAR->value => [
            'label' => 'Registrar',
            'description' => 'Handles admissions, enrollment and student records.',
        ],
        Role::STAFF->value => [
            'label' => 'Staff',
            'description' => 'Teaching or non-teaching staff member. The staff record carries the staff type.',
        ],
        Role::STUDENT->value => [
            'label' => 'Student',
            'description' => 'Authenticated user with restricted, self-service access.',
        ],
    ];

    public function run(): void
    {
        foreach ($this->roles as $name => $attributes) {
            RoleModel::query()->updateOrCreate(['name' => $name], $attributes);
        }
    }
}

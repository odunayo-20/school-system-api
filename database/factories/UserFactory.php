<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Enums\StaffType;
use App\Enums\UserStatus;
use App\Models\Role as RoleModel;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'status' => UserStatus::ACTIVE,
            'role_id' => RoleModel::factory(),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function status(UserStatus $status): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => $status,
        ]);
    }

    public function inactive(): static
    {
        return $this->status(UserStatus::INACTIVE);
    }

    public function suspended(): static
    {
        return $this->status(UserStatus::SUSPENDED);
    }

    public function withRole(Role $role): static
    {
        return $this->state(fn (array $attributes): array => [
            'role_id' => RoleModel::query()->firstOrCreate(
                ['name' => $role->value],
                ['label' => Str::headline($role->value),
                    'description' => null],
            )->getKey(),
        ]);
    }

    public function superAdmin(): static
    {
        return $this->withRole(Role::SUPER_ADMIN);
    }

    public function admin(): static
    {
        return $this->withRole(Role::ADMIN);
    }

    public function registrar(): static
    {
        return $this->withRole(Role::REGISTRAR);
    }

    public function student(): static
    {
        return $this->withRole(Role::STUDENT);
    }

    public function staff(?StaffType $staffType = null): static
    {
        return $this->withRole(Role::STAFF)->afterCreating(function (User $user) use ($staffType): void {
            Staff::query()->firstOrCreate(
                ['user_id' => $user->getKey()],
                ['staff_type' => $staffType ?? StaffType::TEACHING],
            );
        });
    }

    public function teachingStaff(): static
    {
        return $this->staff(StaffType::TEACHING);
    }

    public function nonTeachingStaff(): static
    {
        return $this->staff(StaffType::NON_TEACHING);
    }
}

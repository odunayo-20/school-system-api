<?php

namespace Database\Factories;

use App\Enums\EmploymentStatus;
use App\Enums\Role;
use App\Enums\StaffType;
use App\Models\Staff;
use App\Models\User;
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
            // Module 01 defined staff.user_id as NOT NULL and UNIQUE - one staff record per
            // login - and this factory used to return null for it. Every Module 01 test
            // passed a user_id explicitly, so the contradiction was never reached and
            // Staff::factory()->create() with no overrides failed with an integrity error.
            //
            // The user is created here rather than expected, so a staff record cannot be
            // built without an identity behind it. withRole(STAFF) is used rather than
            // UserFactory's bare default because that default is RoleModel::factory(),
            // which creates a *new* ADMIN role - and roles.name is unique, so against the
            // roles RoleSeeder has already inserted it is a duplicate-key failure rather
            // than a user. withRole resolves the existing role with firstOrCreate, and it
            // does not create a staff row of its own, so there is no recursion through
            // UserFactory::staff().
            'user_id' => User::factory()->withRole(Role::STAFF),
            'staff_type' => StaffType::TEACHING,
            'staff_number' => null,
            'status' => EmploymentStatus::ACTIVE,
            'employment_date' => null,
            'phone' => null,
            'designation' => null,
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

    public function status(EmploymentStatus $status): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => $status,
        ]);
    }

    public function inactive(): static
    {
        return $this->status(EmploymentStatus::INACTIVE);
    }

    public function terminated(): static
    {
        return $this->status(EmploymentStatus::TERMINATED);
    }

    /**
     * With an explicit staff number, which is what the API derives when a client does not
     * supply one.
     */
    public function number(string $number): static
    {
        return $this->state(fn (array $attributes): array => [
            'staff_number' => $number,
        ]);
    }

    /**
     * Employed on a given date.
     */
    public function employedOn(string $date): static
    {
        return $this->state(fn (array $attributes): array => [
            'employment_date' => $date,
        ]);
    }

    /**
     * The linked user account, for tests that need to set or inspect the account itself.
     *
     * Employment status is deliberately NOT copied from the user's account status. The two
     * are independent by design - see EmploymentStatus - and a factory that quietly
     * derived one from the other would hide that until a test asserted the mismatch.
     */
    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes): array => [
            'user_id' => $user->getKey(),
        ]);
    }
}

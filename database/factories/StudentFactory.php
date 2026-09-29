<?php

namespace Database\Factories;

use App\Enums\Gender;
use App\Enums\Role;
use App\Enums\StudentStatus;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Student>
 */
class StudentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $first = fake()->firstName();

        return [
            // Null by default, and that is the honest default: a pupil is a person, and
            // most pupils in this system have no portal login. The column exists and is
            // unique so a login can be linked later by the portal module.
            //
            // It is NOT a RoleModel::factory() the way UserFactory's bare default is, which
            // is the trap documented in StaffFactory: roles.name is unique and RoleSeeder
            // has already inserted the roles, so creating one again is a duplicate-key
            // failure. forUser() below is the opt-in, and it uses the existing role.
            'user_id' => null,
            'student_number' => null,
            'first_name' => $first,
            'middle_name' => null,
            'last_name' => fake()->lastName(),
            // A date of birth in the past, so the default factory output is always
            // plausible. Set explicitly rather than random between 5 and 25 years ago,
            // because a future module's age-based rules should not be tested against a
            // newborn by accident.
            'date_of_birth' => fake()->dateTimeBetween('-18 years', '-4 years')->format('Y-m-d'),
            'gender' => fake()->randomElement(Gender::cases()),
            'status' => StudentStatus::ACTIVE,
        ];
    }

    public function status(StudentStatus $status): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => $status,
        ]);
    }

    public function inactive(): static
    {
        return $this->status(StudentStatus::INACTIVE);
    }

    public function graduated(): static
    {
        return $this->status(StudentStatus::GRADUATED);
    }

    public function withdrawn(): static
    {
        return $this->status(StudentStatus::WITHDRAWN);
    }

    public function male(): static
    {
        return $this->state(fn (array $attributes): array => [
            'gender' => Gender::MALE,
        ]);
    }

    public function female(): static
    {
        return $this->state(fn (array $attributes): array => [
            'gender' => Gender::FEMALE,
        ]);
    }

    /**
     * With an explicit student number, which is what the API derives when a client does not
     * supply one.
     */
    public function number(string $number): static
    {
        return $this->state(fn (array $attributes): array => [
            'student_number' => $number,
        ]);
    }

    /**
     * With a name, for the tests that search by one.
     */
    public function named(string $first, ?string $last = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'first_name' => $first,
            'last_name' => $last,
        ]);
    }

    /**
     * Linked to an existing pupil account.
     *
     * Module 04 exposes no API path that sets user_id - see Student::$fillable - so this is
     * how a test produces a pupil WITH a portal account, which is what the resource's
     * account_status field and the "student CRUD cannot touch a linked account" tests need.
     *
     * The account's own status is deliberately NOT copied onto the pupil's status. The two
     * are independent by design, and a factory that quietly derived one from the other
     * would hide that until a test asserted the mismatch.
     */
    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes): array => [
            'user_id' => $user->getKey(),
        ]);
    }

    /**
     * A pupil with a brand new portal account, for the tests above.
     *
     * withRole(Role::STUDENT) is used rather than UserFactory::student() so the role is
     * resolved against the seeded row, and rather than UserFactory's bare default for the
     * duplicate-role reason in definition().
     */
    public function withAccount(): static
    {
        return $this->state(fn (array $attributes): array => [
            'user_id' => User::factory()->withRole(Role::STUDENT),
        ]);
    }

    /**
     * A pupil number derived the way the API derives one, for a pupil built with a
     * particular id.
     */
    public function derivedNumber(): static
    {
        return $this->afterMaking(function (Student $student): void {
            if (is_null($student->student_number)) {
                $student->student_number = 'STU-'.Str::padLeft((string) $student->getKey(), 4, '0');
            }
        });
    }
}

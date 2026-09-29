<?php

namespace Database\Factories;

use App\Enums\AdmissionStatus;
use App\Enums\Gender;
use App\Models\AcademicSession;
use App\Models\Admission;
use App\Models\ClassLevel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Admission>
 */
class AdmissionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'academic_session_id' => AcademicSession::factory(),
            'entry_class_level_id' => null,
            'admission_number' => null,
            'first_name' => fake()->firstName(),
            'middle_name' => null,
            'last_name' => fake()->lastName(),
            'date_of_birth' => fake()->dateTimeBetween('-18 years', '-4 years')->format('Y-m-d'),
            'gender' => fake()->randomElement(Gender::cases()),
            'status' => AdmissionStatus::PENDING,
            'notes' => null,
            'decided_at' => null,
        ];
    }

    public function forSession(AcademicSession $session): static
    {
        return $this->state(fn (array $attributes): array => [
            'academic_session_id' => $session->getKey(),
        ]);
    }

    public function forEntryLevel(ClassLevel $classLevel): static
    {
        return $this->state(fn (array $attributes): array => [
            'entry_class_level_id' => $classLevel->getKey(),
        ]);
    }

    public function named(string $first, ?string $last = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'first_name' => $first,
            'last_name' => $last,
        ]);
    }

    public function number(string $number): static
    {
        return $this->state(fn (array $attributes): array => [
            'admission_number' => $number,
        ]);
    }

    /**
     * An admission number derived the way the API derives one, for a record built with a
     * particular id.
     */
    public function derivedNumber(): static
    {
        return $this->afterMaking(function (Admission $admission): void {
            if (is_null($admission->admission_number)) {
                $admission->admission_number = 'ADM-'.Str::padLeft((string) $admission->getKey(), 4, '0');
            }
        });
    }

    public function admitted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => AdmissionStatus::ADMITTED,
            'decided_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => AdmissionStatus::REJECTED,
            'decided_at' => now(),
        ]);
    }

    public function withdrawn(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => AdmissionStatus::WITHDRAWN,
            'decided_at' => now(),
        ]);
    }
}

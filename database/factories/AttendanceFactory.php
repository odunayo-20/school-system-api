<?php

namespace Database\Factories;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\Enrollment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Attendance>
 *
 * Factory-created models bypass $fillable entirely (Factory::make() wraps instantiation in
 * Model::unguarded()), which is what lets this definition set enrollment_id/academic_session_id/
 * school_class_id/section_id/date directly even though none is mass-assignable through the API
 * - the identical situation ScoreFactory and every other anchor-style factory in this project
 * is already in for its own guarded reference fields.
 *
 * academic_session_id/school_class_id/section_id are always derived from the SAME enrollment
 * the row references, never randomised independently - anything else would build a row the
 * real system can never produce, since AttendanceService always copies these three from the
 * enrollment it is given. See forEnrollment().
 */
class AttendanceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $enrollment = Enrollment::factory()->create();

        return [
            'enrollment_id' => $enrollment->id,
            'academic_session_id' => $enrollment->academic_session_id,
            'school_class_id' => $enrollment->school_class_id,
            'section_id' => $enrollment->section_id,
            'date' => fake()->unique()->dateTimeBetween('-60 days', 'now')->format('Y-m-d'),
            'status' => AttendanceStatus::PRESENT,
            'remarks' => null,
            'recorded_by' => null,
        ];
    }

    /**
     * Every reference column copied from one real enrollment, matching exactly what
     * AttendanceService::create() itself would write for it.
     */
    public function forEnrollment(Enrollment $enrollment): static
    {
        return $this->state(fn (array $attributes): array => [
            'enrollment_id' => $enrollment->getKey(),
            'academic_session_id' => $enrollment->academic_session_id,
            'school_class_id' => $enrollment->school_class_id,
            'section_id' => $enrollment->section_id,
        ]);
    }

    public function on(string $date): static
    {
        return $this->state(fn (array $attributes): array => [
            'date' => $date,
        ]);
    }

    public function present(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => AttendanceStatus::PRESENT]);
    }

    public function absent(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => AttendanceStatus::ABSENT]);
    }

    public function late(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => AttendanceStatus::LATE]);
    }

    public function excused(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => AttendanceStatus::EXCUSED]);
    }
}

<?php

namespace Database\Factories;

use App\Enums\EnrollmentStatus;
use App\Models\AcademicSession;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Enrollment>
 *
 * Factory-created models bypass $fillable entirely (Factory::make() wraps instantiation in
 * Model::unguarded()), which is what lets this definition set student_id/academic_session_id/
 * school_class_id/section_id/status directly even though none of them is mass-assignable
 * through the API - the identical situation AdmissionFactory and StudentFactory are already
 * in for their own guarded fields.
 */
class EnrollmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Built as a real hierarchy rather than three independent factory placeholders, so
        // the default record is valid on its own: the section genuinely belongs to the
        // class, and the enrollment date genuinely falls inside the session - the same
        // validation EnrollmentService itself enforces.
        $section = Section::factory()->create();
        $session = AcademicSession::factory()->create();

        return [
            'student_id' => Student::factory(),
            'academic_session_id' => $session->id,
            'school_class_id' => $section->school_class_id,
            'section_id' => $section->id,
            'enrollment_date' => $session->start_date,
            'status' => EnrollmentStatus::ACTIVE,
            'notes' => null,
            'status_changed_at' => null,
        ];
    }

    public function forStudent(Student $student): static
    {
        return $this->state(fn (array $attributes): array => [
            'student_id' => $student->getKey(),
        ]);
    }

    public function forSession(AcademicSession $session): static
    {
        return $this->state(fn (array $attributes): array => [
            'academic_session_id' => $session->getKey(),
            'enrollment_date' => $session->start_date,
        ]);
    }

    public function inSection(Section $section): static
    {
        return $this->state(fn (array $attributes): array => [
            'school_class_id' => $section->school_class_id,
            'section_id' => $section->getKey(),
        ]);
    }

    public function withdrawn(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => EnrollmentStatus::WITHDRAWN,
            'status_changed_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => EnrollmentStatus::CANCELLED,
            'status_changed_at' => now(),
        ]);
    }
}

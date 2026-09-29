<?php

namespace Database\Factories;

use App\Enums\StaffType;
use App\Enums\TeacherAssignmentStatus;
use App\Models\AcademicSession;
use App\Models\ClassSubject;
use App\Models\Staff;
use App\Models\TeacherAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TeacherAssignment>
 *
 * Factory-created models bypass $fillable entirely (Factory::make() wraps instantiation in
 * Model::unguarded()), which is what lets this definition set teaching_staff_id/
 * class_subject_id/academic_session_id/status directly even though none is mass-assignable
 * through the API - the identical situation EnrollmentFactory and ClassSubjectFactory are
 * already in for their own guarded fields.
 */
class TeacherAssignmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'teaching_staff_id' => Staff::factory()->state(['staff_type' => StaffType::TEACHING]),
            'class_subject_id' => ClassSubject::factory(),
            'academic_session_id' => AcademicSession::factory(),
            'status' => TeacherAssignmentStatus::ACTIVE,
            'active_marker' => true,
            'notes' => null,
            'ended_at' => null,
        ];
    }

    public function forTeacher(Staff $staff): static
    {
        return $this->state(fn (array $attributes): array => [
            'teaching_staff_id' => $staff->getKey(),
        ]);
    }

    public function forClassSubject(ClassSubject $classSubject): static
    {
        return $this->state(fn (array $attributes): array => [
            'class_subject_id' => $classSubject->getKey(),
        ]);
    }

    public function forSession(AcademicSession $session): static
    {
        return $this->state(fn (array $attributes): array => [
            'academic_session_id' => $session->getKey(),
        ]);
    }

    public function ended(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => TeacherAssignmentStatus::ENDED,
            'active_marker' => null,
            'ended_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => TeacherAssignmentStatus::CANCELLED,
            'active_marker' => null,
            'ended_at' => now(),
        ]);
    }
}

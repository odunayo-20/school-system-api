<?php

namespace Database\Factories;

use App\Enums\CatalogStatus;
use App\Models\ClassSubject;
use App\Models\SchoolClass;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClassSubject>
 *
 * Factory-created models bypass $fillable entirely (Factory::make() wraps instantiation in
 * Model::unguarded()), which is what lets this definition set school_class_id/subject_id
 * directly even though neither is mass-assignable through the API - the identical situation
 * EnrollmentFactory is already in for its own guarded placement fields.
 */
class ClassSubjectFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'school_class_id' => SchoolClass::factory(),
            'subject_id' => Subject::factory(),
            'status' => CatalogStatus::ACTIVE,
        ];
    }

    public function forClass(SchoolClass $schoolClass): static
    {
        return $this->state(fn (array $attributes): array => [
            'school_class_id' => $schoolClass->getKey(),
        ]);
    }

    public function forSubject(Subject $subject): static
    {
        return $this->state(fn (array $attributes): array => [
            'subject_id' => $subject->getKey(),
        ]);
    }

    public function status(CatalogStatus $status): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => $status,
        ]);
    }
}

<?php

namespace Database\Factories;

use App\Enums\CatalogStatus;
use App\Models\Assessment;
use App\Models\AssessmentType;
use App\Models\ClassSubject;
use App\Models\Term;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Assessment>
 *
 * Factory-created models bypass $fillable entirely (Factory::make() wraps instantiation in
 * Model::unguarded()), which is what lets this definition set class_subject_id/term_id/
 * assessment_type_id directly even though none is mass-assignable through the API - the
 * identical situation ClassSubjectFactory and TeacherAssignmentFactory are already in for
 * their own guarded fields.
 */
class AssessmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'class_subject_id' => ClassSubject::factory(),
            'term_id' => Term::factory(),
            'assessment_type_id' => AssessmentType::factory(),
            // unique() so two factory-built assessments never collide on the
            // (class_subject_id, term_id, name) index by sharing a default class subject/term
            // pair - the identical reasoning SubjectFactory gives its own unique() name.
            'name' => 'CA '.fake()->unique()->numberBetween(1, 1000000),
            'max_score' => 20,
            'weight' => null,
            'sort_order' => fake()->numberBetween(1, 20),
            'status' => CatalogStatus::ACTIVE,
        ];
    }

    public function forClassSubject(ClassSubject $classSubject): static
    {
        return $this->state(fn (array $attributes): array => [
            'class_subject_id' => $classSubject->getKey(),
        ]);
    }

    public function forTerm(Term $term): static
    {
        return $this->state(fn (array $attributes): array => [
            'term_id' => $term->getKey(),
        ]);
    }

    public function forAssessmentType(AssessmentType $assessmentType): static
    {
        return $this->state(fn (array $attributes): array => [
            'assessment_type_id' => $assessmentType->getKey(),
        ]);
    }

    public function status(CatalogStatus $status): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => $status,
        ]);
    }
}

<?php

namespace Database\Factories;

use App\Models\Assessment;
use App\Models\Enrollment;
use App\Models\Score;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Score>
 *
 * Factory-created models bypass $fillable entirely (Factory::make() wraps instantiation in
 * Model::unguarded()), which is what lets this definition set assessment_id/enrollment_id
 * directly even though neither is mass-assignable through the API - the identical situation
 * ClassSubjectFactory, TeacherAssignmentFactory and AssessmentFactory are already in for their
 * own guarded reference fields.
 */
class ScoreFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'assessment_id' => Assessment::factory(),
            'enrollment_id' => Enrollment::factory(),
            'score' => fake()->randomFloat(2, 0, 20),
            'remarks' => null,
        ];
    }

    public function forAssessment(Assessment $assessment): static
    {
        return $this->state(fn (array $attributes): array => [
            'assessment_id' => $assessment->getKey(),
        ]);
    }

    public function forEnrollment(Enrollment $enrollment): static
    {
        return $this->state(fn (array $attributes): array => [
            'enrollment_id' => $enrollment->getKey(),
        ]);
    }

    public function value(string|float $score): static
    {
        return $this->state(fn (array $attributes): array => [
            'score' => $score,
        ]);
    }
}
